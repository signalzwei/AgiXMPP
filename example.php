<?php
require_once 'XMPP.php';

$config = require_once (!file_exists('config.mine.php') ? 'config.php' : 'config.mine.php');

$xmpp = new XMPP\Client($config);
$xmpp->connect();

// add your awesome event handlers somewhere here

$xmpp->init();
$xmpp->disconnect();


/*
Example:

// handler names are registered based on the xml tags
// these three (iq, message, presence) are now captured as events and the object ExampleHandler handles them
$xmpp->addEventHandlers(array('iq', 'message', 'presence'), new ExampleHandler());

class ExampleHandler extends \XMPP\EventHandlers\EventReceiver
{
  /**
   * @param string $event
   *-/
  public function onEvent($event, $context)
  {
    echo '~ Event: '.$event;
    echo ' -> ';
    print_r($this->getResponse()->getTag($event));
    echo PHP_EOL;

    // now let's trigger something
    $context->getConnection()->triggerEvent('awesome_trigger');
  }

  /**
   * This is globally called in every event handler, when you trigger something
   *
   * @param string $trigger
   *-/
  public function onTrigger($trigger)
  {
    if ($trigger == 'awesome_trigger') {
      echo 'wait! i am awesome!';
    } else {
      echo '~ Trigger: '.$trigger . PHP_EOL;
    }
  }
}
*/