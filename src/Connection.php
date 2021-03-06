<?php
/**
 * @author Daniel Lehr <daniel@agixo.de>
 * @internal-coding = utf-8
 * @internal UTF-Chars: ÄÖÜäöüß∆
 * created on 29.11.11 21:15.
 */
namespace AgiXMPP;

use AgiXMPP\XML\Parser;
use AgiXMPP\Utility\Storage;
use AgiXMPP\Transport\MessageDecorator;
use AgiXMPP\Transport\TransportInterface;

use AgiXMPP\EventHandlers\EventHandler;
// default handlers which are registered in registerDefaultHandlers()
use AgiXMPP\EventHandlers\Core\StreamHandler;
use AgiXMPP\EventHandlers\Core\BoshStreamHandler;
use AgiXMPP\EventHandlers\XEP\PING_199\PingHandler;
use AgiXMPP\EventHandlers\IM\RosterHandler;
use AgiXMPP\EventHandlers\IM\PresenceHandler;
use AgiXMPP\EventHandlers\Trigger;
use Fabiang\Sasl\Sasl;

class Connection
{
  /**
   * @var string Remote XMPP server
   */
  public $host;

  /**
   * @var int Remote XMPP server port
   */
  public $port;

  /**
   * @var Client
   */
  public $client;

  /**
   * @var string
   */
  public $sid;

  /**
   * @var int
   */
  public $rid;

  /**
   * @var TransportInterface The basic socket stream
   */
  private $transport;

  /**
   * @var Parser
   */
  private $xmlParser;

  /**
   * @var EventHandler[]
   */
  private $eventHandlers = array();

  /**
   * @var Storage
   */
  public $storage;

  /**
   * @var string
   */
  private $resolveResponseId = null;

  /**
   * @var int
   */
  private static $_uid = 0;

  /**
   * @param Client $client
   * @param string $host
   * @param int $port
   */
  public function __construct(Client $client, $host, $port)
  {
    $this->host = $host;
    $this->port = $port;
    $this->client = $client;

    $transport = isset($client->config['transport']) ? $client->config['transport'] : 'socket';
    switch (strtolower($transport)) {
      case 'bosh':
        $this->transport = new Transport\BOSH();
        $this->rid = mt_rand(100000, 1000000000);
        $this->addEventHandler(new BoshStreamHandler());
        break;
      default:
        $this->transport = new Transport\Socket();
        $this->addEventHandler(new StreamHandler());
    }

    $this->xmlParser = new Parser();
    $this->storage = new Storage();

    $this->registerDefaultHandlers();
  }

  /**
   * just try it
   */
  public function __destruct()
  {
    $this->disconnect();
  }

  /**
   * Connects to the host with the settings from the config (set in the constructor)
   * @return bool
   */
  public function connect()
  {
    $conn = $this->transport->open($this->host, $this->port);
    Logger::log('Attempting to connect to '.$this->host.':'.$this->port.'.');

    if ($conn) {
      $this->trigger(Trigger::INIT_STREAM);

      do {
        if ($this->receive()) {
          $response = new Response($this->xmlParser->getTree());
          $this->handleEvents($response);
        }
      } while(!$this->transport->hasTimedOut() && $this->transport->isConnected());

      return true;
    }
    Logger::err('Could not connect to host.', true);
    return false;
  }

  /**
   * Reconnects the socket stream
   *
   * @param bool $closeStream
   */
  public function reconnect($closeStream = false)
  {
    $this->disconnect($closeStream);
    $this->connect();
  }

  /**
   * @param bool $closeStream
   */
  public function disconnect($closeStream = false)
  {
    if ($closeStream) {
      $this->transport->send(StreamHandler::XMPP_TERMINATE_STREAM);
    }
    $this->transport->close();
    Logger::log('Disconnected');
  }

  /**
   * @param string $mechanism
   * @param array $data
   * @return \Fabiang\Sasl\Authentication\AuthenticationInterface
   */
  public function createSASLAuth($mechanism, $data)
  {
    $auth = new Sasl();
    return $auth->factory($mechanism, $data);
  }

  /**
   * Generates an unique identifier for outgoing stanzas
   *
   * @return string
   */
  public static function UID()
  {
    return 'agixmpp_'.self::$_uid++;
  }

  /**
   * @return bool
   */
  private function receive()
  {
    $buffer = $this->isEnclosedBuffer($this->transport->read());

    if ($buffer !== false && strlen($buffer) > 0) {
      if ($buffer == StreamHandler::XMPP_TERMINATE_STREAM) {
        $this->transport->close();
      } else {
        return $this->xmlParser->isValid($buffer);
      }
    }
    return false;
  }

  /**
   * Determine if the XML buffer is completely enclosed with '<' and '>'.
   *
   * @param $buffer
   * @return bool|string
   */
  private function isEnclosedBuffer($buffer)
  {
    static $unclosedTags = 0, $fullBuffer = '';

    $tagOpenCount = substr_count($buffer, '<');
    $tagCloseCount = substr_count($buffer, '>');

    if ($tagOpenCount + $unclosedTags != $tagCloseCount) {
      $fullBuffer .= $buffer;
      $unclosedTags += $tagOpenCount - $tagCloseCount;
      return false;
    }
    $enclosedBuffer = $fullBuffer.$buffer;
    $unclosedTags = 0;
    $fullBuffer = '';
    return $enclosedBuffer;
  }

  /**
   * @param string $data
   * @param array $args
   * @param bool $awaitsResponse
   * @param bool $blockUntilResolved
   * @return \AgiXMPP\Message
   */
  public function send($data, $args = array(), $awaitsResponse = false, $blockUntilResolved = false)
  {
    $message = count($args) > 0 ? vsprintf($data, $args) : $data;
    $messageEvent = new Message($message, $awaitsResponse);
    $this->addEventHandler($messageEvent);

    $preparedMessage = new MessageDecorator($messageEvent, $this->transport, $this);
//    $preparedMessage = $messageEvent->preparedMessage;
    $this->transport->send($preparedMessage->getMessage());

    if ($blockUntilResolved) {
      $this->blockEvents($messageEvent->uid);
    }

    return $messageEvent;
  }

  /**
   * @return array
   */
  public function getSessionInfo()
  {
    if ($this->jid && $this->sid && $this->rid) {
      return array(
        'jid' => $this->jid,
        'sid' => $this->sid,
        'rid' => $this->rid
      );
    }
  }

  /**
   * @param $id
   */
  private function blockEvents($id)
  {
    $this->resolveResponseId = $id;
  }

  /**
   * @return bool
   */
  public function isBlocked()
  {
    return !is_null($this->resolveResponseId);
  }

  /**
   *
   */
  private function unblockEvents()
  {
    $this->resolveResponseId = null;
  }

  /**
   *
   */
  private function registerDefaultHandlers()
  {
    $this->addEventHandler(new PingHandler());
    $this->addEventHandler(new PresenceHandler());
    $this->addEventHandler(new RosterHandler());
  }

  /**
   * @param EventHandlers\EventHandler $handler
   * @param int $priority
   */
  public function addEventHandler(EventHandler $handler, $priority = EventHandler::PRIORITY_NORMAL)
  {
    $handler->priority = $priority;
    $handler->connection = $this;
    $this->eventHandlers[] = $handler;
    $handler->onMount();
  }

  private function getEventHandlers()
  {
    if (count($this->eventHandlers) > 1) {
      usort($this->eventHandlers, array($this, 'sortEventHandlerByPriority'));
    }

    return $this->eventHandlers;
  }

  /**
   * @param \AgiXMPP\Response $response
   */
  private function handleEvents(Response $response)
  {
    foreach($this->getEventHandlers() as $handler) {
      foreach($handler->getEvents() as $eventTag => $events) {
        /** @var EventHandlers\Event[] $events */
        foreach($events as $event) {

          // check if event loop is blocked by an event
          if ($this->isBlocked()) {
            if ($response->hasAttributeValue('id', $this->resolveResponseId)) {
              $this->unblockEvents();
            } else {
              return;
            }
          }
          if ($response->has($eventTag)) {
            $this->invokeEvent($event->callback, array($response, $this));
          }
        }
      }
    }
  }

  /**
   * @param string $triggerName
   */
  public function trigger($triggerName)
  {
    if ($this->isBlocked()) {
      return;
    }

    foreach($this->getEventHandlers() as $handler) {
      /** @var EventHandlers\Event[] $triggers */
      $triggers = $handler->getTriggers();
      foreach($triggers as $trigger) {
        if ($triggerName === $trigger->name) {
          $this->invokeEvent($trigger->callback, array($this));
        }
      }
    }
  }

  /**
   * Sort higher prioritized events to top of array and vice versa.
   *
   * @param $a
   * @param $b
   * @return int
   */
  private function sortEventHandlerByPriority($a, $b)
  {
    if ($a->priority == $b->priority) {
      return 0;
    }
    return $a->priority > $b->priority ? -1 : 1;
  }

  /**
   * @param $callback
   * @param $parameters
   */
  public function invokeEvent($callback, $parameters)
  {
    if (is_callable($callback) || is_array($callback)) {
      call_user_func_array($callback, $parameters);
    }
  }

  /**
   * @return \AgiXMPP\Transport\TransportInterface
   */
  public function getTransport()
  {
    return $this->transport;
  }
}