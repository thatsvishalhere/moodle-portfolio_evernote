<?php
class Moodle_THttpClient extends THttpClient {
    public function flush() {
    global $CFG;
    // God, PHP really has some esoteric ways of doing simple things.
    $host = $this->host_.($this->port_ != 80 ? ':'.$this->port_ : '');

    $headers = array();
    $defaultHeaders = array('Host' => $host,
      'Accept' => 'application/x-thrift',
      'User-Agent' => 'PHP/THttpClient',
      'Content-Type' => 'application/x-thrift',
      'Content-Length' => strlen($this->buf_));
    foreach (($this->headers_ + $defaultHeaders) as $key => $value) {
      $headers[] = "$key: $value";
    }

    $options = array('method' => 'POST', 'protocol_version' => 1.1,
                     'header' => implode("\r\n", $headers),
                     'max_redirects' => 1,
                     'content' => $this->buf_,);

    // Applying the proxy settings.
    if(!empty($CFG->proxyhost) && $CFG->proxytype === 'HTTP') {
        $options['proxy'] = 'tcp://'.$CFG->proxyhost.':'.$CFG->proxyport;
        if(!empty($CFG->proxyuser)) {
            $auth = base64_encode($CFG->proxyuser.':'.$CFG->proxypassword);
            $options['request_fulluri'] = true;
            $options['header'] = "Proxy-Authorization: Basic $auth";
        }
    }
    if ($this->timeout_ > 0) {
      $options['timeout'] = $this->timeout_;
    }
    $this->buf_ = '';

    $contextid = stream_context_create(array('http' => $options));
    $this->handle_ = @fopen($this->scheme_.'://'.$host.$this->uri_, 'r', false, $contextid);

    // Connect failed?
    if ($this->handle_ === FALSE) {
      $this->handle_ = null;
      $error = 'THttpClient: Could not connect to '.$host.$this->uri_;
      throw new TTransportException($error, TTransportException::NOT_OPEN);
    }
  }
}