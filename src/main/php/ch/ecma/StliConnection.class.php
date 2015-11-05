<?php namespace ch\ecma;

use lang\IllegalStateException;
use util\telephony\TelephonyCall;
use util\telephony\TelephonyException;
use util\telephony\TelephonyProvider;
use util\telephony\TelephonyTerminal;

/**
 * STLI Client
 *
 * <quote>
 * STLI stands for "Simple Telephony Interface". The TeamCall Server and the client
 * application can communicate by using this protocol, similar to the communication
 * between a webserver and a client. We designed STLI to provide basic, but easy to
 * implement CTI functionalities. STLI is a time saving and cost effective opportunity
 * to implement CTI functions in every TCP/IP application, including scripting
 * languages like Perl or Python. A detailed documentation about the STLI interface is
 * part of every ilink TeamCall Server distribution package.
 * </quote>
 *
 * @purpose  Provide an interface to STLI server
 * @see      http://www.ilink.de/home/de/cti/products/TeamCallServer/
 * @see      http://www.ecma.ch/ecma1/STAND/ECMA-180.HTM
 * @see      http://www.ecma.ch/ecma1/STAND/ECMA-179.HTM
 * @see      http://www.ecma.ch/ecma1/STAND/ECMA-217.HTM
 * @see      http://www.ecma.ch/ecma1/STAND/ECMA-218.HTM
 * @see      http://www.ecma.ch/ecma1/STAND/ECMA-269.HTM
 * @see      http://www.ecma.ch/ecma1/STAND/ECMA-285.HTM
 * @see      http://www.ecma.ch/ecma1/STAND/ecma-323.htm
 */
class StliConnection extends TelephonyProvider {

  // Response codes
  const STLI_INIT_RESPONSE =       'error_ind SUCCESS STLI Version "%d"';
  const STLI_BYE_RESPONSE =        'error_ind SUCCESS BYE';
  const STLI_MON_START_RESPONSE =  'error_ind SUCCESS MonitorStart';
  const STLI_MON_STOP_RESPONSE =   'error_ind SUCCESS MonitorStop';
  const STLI_MAKECALL_RESPONSE =   'error_ind SUCCESS MakeCall';
  const STLI_MON_CALL_INITIATED =  'Initiated %d makeCall %s %s';
  const STLI_MON_CALL_DEVICEINFO = 'DeviceInformation %d %d (%s)';

  // Supported protocol versions
  const STLI_VERSION_2 =       2;

  private
    $sock       = null,
    $version    = 0;

  /**
   * Constructor.
   * Takes a peer.Socket object as argument, use as follows:
   * <code>
   *   // [...]
   *   $c= new StliClient(new Socket($stliServer, $stliPort));
   *   // [...]
   * </code>
   *
   * @param   peer.Socket sock
   * @param   int version default STLI_VERSION_2
   */
  public function __construct($sock, $version= self::STLI_VERSION_2) {
    $this->sock= $sock;
    $this->version= $version;
  }
  
  /**
   * Writes data to the socket.
   *
   * @param   string buf
   */
  protected function _write($buf) {
    $this->trace('>>>', $buf);
    $this->sock->write($buf."\n");
  }

  /**
   * Reads data from the socket
   *
   * @return  string
   */
  protected function _read() {
    $read= chop($this->sock->read());
    $this->trace('<<<', $read);
    return $read;
  }

  /**
   * Set the protocol version. This can only be done *prior* to connecting to
   * the server!
   *
   * @param   int version
   * @throws  lang.IllegalStateException in case already having connected
   */
  public function setVersion($version) {
    if ($this->sock->isConnected()) {
      throw new IllegalStateException('Cannot set version after already having connected');
    }

    $this->version= $version;
  }

  /**
   * Private helper function
   *
   */
  protected function _sockcmd() {
    $args= func_get_args();
    $write= vsprintf($args[0], array_slice($args, 1));
    
    // Write command
    $this->_write($write);
    
    // Read response
    return $this->_read();
  }

  /**
   * Private helper function
   *
   */
  protected function _expect($expect, $have) {
    if ($expect !== $have) {
      throw new TelephonyException(sprintf(
        'Protocol error: Expecting "%s", have "%s"', $expect, $have
      ));
    }
    
    return $have;
  }

  /**
   * Private helper function
   *
   */
  protected function _expectf($expect, $have) {
    $res= sscanf($have, $expect);

    foreach ($res as $val) {
      if (is_null($val)) {
        throw new TelephonyException(sprintf(
          'Protocol error: Expecting "%s", have "%s"', $expect, $have
        ));
        return false;
      }
    }

    return $have;
  }

  /**
   * Connect and initiate the communication
   *
   * @return  mixed the return code of the socket's connect method
   * @throws  util.telephony.TelephonyException in case a protocol error occurs
   */
  public function connect() {
    $this->sock->connect();

    // Send initialization string and check response
    $r= $this->_sockcmd('STLI;Version=%d', $this->version);

    // Two different formats here, check for common denominator
    //
    // error_ind SUCCESS STLI Version "2"
    // error_ind SUCCESS STLI;Version=2;DeviceInformation=Standard
    //
    // The semicolon in the second will serve as indicator for the
    // latter (';' == $ind)
    if (3 === sscanf($r, "error_ind %s STLI%c%[^\r]", $status, $ind, $inf)) {
      if ('SUCCESS' !== $status) {
        throw new TelephonyException('Response indicates failure <'.$r.'>');
      }
      return $r;
    }

    throw new TelephonyException('Cannot parse connect respone <'.$r.'>');
  }

  /**
   * Close connection and end the communication
   *
   * @return  mixed the return code of the socket's close method
   * @throws  util.telephony.TelephonyException in case a protocol error occurs
   */
  public function close() {
    if (false === $this->_expect(self::STLI_BYE_RESPONSE, $this->_sockcmd('BYE'))) {
      return false;
    }
    
    return $this->sock->close();
  }

  /**
   * Create a call
   *
   * @param   util.telephony.TelephonyTerminal terminal
   * @param   util.telephony.TelephonyAddress destination
   * @return  util.telephony.TelephonyCall a call object
   */
  public function createCall($terminal, $destination) {
    if (false === $this->_expect(
      self::STLI_MAKECALL_RESPONSE,
      $this->_sockcmd('MakeCall %s %s',
        $terminal->getAttachedNumber(),
        $destination->getNumber()
    ))) return null;

    if ($terminal->isObserved()) {
      if (
        !$this->_expectf(self::STLI_MON_CALL_INITIATED, $this->_read()) ||
        !$this->_expectf(self::STLI_MON_CALL_DEVICEINFO, $this->_read())
      ) return null;
    }
    return new TelephonyCall($terminal->address, $destination);
  }

  /**
   * Get terminal
   *
   * @param   util.telephony.TelephonyAddress address
   * @return  util.telephony.TelephonyTerminal
   */
  public function getTerminal($address) {
    return new TelephonyTerminal($address);
  }

  /**
   * Observe a terminal
   *
   * @param   util.telephony.TelephonyTerminal terminal
   * @param   bool status TRUE to start observing, FALSE top stop
   * @return  bool success
   */
  public function observeTerminal($terminal, $status) {
    if ($status) {
      $success= $this->_expect(
        self::STLI_MON_START_RESPONSE,
        $this->_sockcmd('MonitorStart %s', $terminal->getAttachedNumber())
      );
      $success && $terminal->setObserved(true);
    } else {
      $success= $this->_expect(
        self::STLI_MON_STOP_RESPONSE,
        $this->_sockcmd('MonitorStop %s', $terminal->getAttachedNumber())
      );
      $success && $terminal->setObserved(false);
    }
    return $success;
  }

  /**
   * Release terminal
   *
   * @param   util.telephony.TelephonyTerminal terminal
   * @return  bool success
   */
  public function releaseTerminal($terminal) {
    return true;
  }
}