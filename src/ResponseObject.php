<?php
/**
 * @author Daniel Lehr, ADITION technologies AG, Freiburg, Germany. <daniel.lehr@adition.com>
 * @internal-coding = utf-8
 * @internal UTF-Chars: ÄÖÜäöüß∆
 * created on 06.01.12 13:29.
 */
namespace XMPP;

use RecursiveIteratorIterator;
use RecursiveArrayIterator;

class ResponseObject
{
  /**
   * @var array
   */
  protected $response = array();

  /**
   * @var array
   */
  protected $filteredResponse = array();

  /**
   * xml2array() (see XMLParser.php) returns in a specific format
   * so all attributes are stored in an own array with the key having a suffix which is '_attr'
   */
  const ATTR_SUFFIX = '_attr';

  /**
   * @param array $response
   */
  public function __construct(array $response)
  {
    $this->response = $response;
  }

  /**
   * @return array
   */
  protected function getResponse()
  {
    if (count($this->filteredResponse) > 0) {
      return $this->filteredResponse;
    }
    return $this->response;
  }

  /**
   * @param string $event
   * @return bool
   */
  public function setFilter($event) {
    if (!$event) {
      $this->filteredResponse = array();
      return true;
    }
    $iterator = new RecursiveIteratorIterator(new RecursiveArrayIterator($this->response), RecursiveIteratorIterator::SELF_FIRST);

    $attrs = $event.self::ATTR_SUFFIX;
    $ret = array();

    foreach($iterator as $val) {
      $key = $iterator->key();

      if ($key === $event || $key === $attrs) {
        $ret[$key] = $val;
      }
    }

    if (isset($ret[$event]) || isset($ret[$attrs])) {
      $this->filteredResponse = $ret;
      return true;
    }
    return false;
  }

  /**
   * @param string $needle
   * @param array $haystack
   * @return mixed
   */
  protected function findDeep($needle, array $haystack)
  {
    $iterator = new RecursiveIteratorIterator(new RecursiveArrayIterator($haystack), RecursiveIteratorIterator::SELF_FIRST);

    foreach($iterator as $val) {
      $key = $iterator->key();

      if ($key === $needle) {
        return $val;
      }
    }
    return false;
  }

  /**
   * @param string $string
   * @return bool
   */
  protected function isAttribute($string)
  {
    return substr($string, strlen($string) - strlen(self::ATTR_SUFFIX)) === self::ATTR_SUFFIX;
  }

  /**
   * @param string $string
   * @return bool
   */
  protected function isTag($string)
  {
    return !$this->isAttribute($string);
  }

  /**
   * @param string $attr
   * @param string $check
   * @return bool
   */
  public function hasAttribute($attr, $check = '')
  {
    $response = $this->getResponse();

    foreach($response as $tag => $val) {
      if ($this->isAttribute($tag)) {
        if (isset($val[$attr])) {
          if (!empty($check)) {
            return $val[$attr] == $check;
          }
          return true;
        }
      }
    }

    return false;
  }

  /**
   * @param string $attr
   * @return mixed
   */
  public function getAttribute($attr)
  {
    $response = $this->getResponse();

    foreach($response as $tag => $val) {
      if ($this->isAttribute($tag)) {
        if (isset($val[$attr])) {
          return $val[$attr];
        }
      }
    }
    return false;
  }

  /**
   * @todo
   */
  public function getAttributes()
  {
    print_r($this->getResponse());
  }

  /**
   * @param string $tag
   * @return bool
   */
  public function hasTag($tag)
  {
    return $this->findDeep($tag, $this->getResponse()) !== false;
  }

  /**
   * @param string $tag
   * @return mixed
   */
  public function getTag($tag)
  {
    return $this->findDeep($tag, $this->getResponse());
  }

  /**
   * @param string $attr
   * @param string $tag
   * @return mixed
   */
  public function getAttributeFromTag($attr, $tag)
  {
    $found = $this->findDeep($tag.self::ATTR_SUFFIX, $this->getResponse());
    if ($found !== false) {
      if (isset($found[$attr])) {
        return $found[$attr];
      }
    }
    return false;
  }
}