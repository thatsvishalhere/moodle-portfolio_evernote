<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Portfolio to access and add Evernote content.
 *
 * @package    portfolio
 * @subpackage evernote
 * @copyright  2013 Vishal Raheja
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();
/**
 * Moodle THttpClient to override the Evernote THttpClient.
 *
 * This class extends the THttpClient of the Evernote API.
 * This class helps the portfolio to access the Moodle connection settings (especially proxy settings).
 * This was not available in the Evernote API.
 *
 * @package    portfolio_evernote
 * @category   portfolio
 * @copyright  2013 Vishal Raheja
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class Moodle_THttpClient extends THttpClient {
    /**
     * Function where the proxy settings need to be applied.
     *
     * @overriden function 
     */
    public function flush() {
        global $CFG;
        $host = $this->host_.($this->port_ != 80 ? ':'.$this->port_ : '');

        $headers = array();
        $defaultheaders = array('Host' => $host,
          'Accept' => 'application/x-thrift',
          'User-Agent' => 'PHP/THttpClient',
          'Content-Type' => 'application/x-thrift',
          'Content-Length' => strlen($this->buf_));
        foreach (($this->headers_ + $defaultheaders) as $key => $value) {
            $headers[] = "$key: $value";
        }

        $options = array('method' => 'POST', 'protocol_version' => 1.1,
                         'header' => implode("\r\n", $headers),
                         'max_redirects' => 1,
                         'content' => $this->buf_, );

        // Applying the proxy settings.
        if (!empty($CFG->proxyhost) && $CFG->proxytype === 'HTTP') {
            $options['proxy'] = 'tcp://'.$CFG->proxyhost.':'.$CFG->proxyport;
            if (!empty($CFG->proxyuser)) {
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
        if ($this->handle_ === false) {
            $this->handle_ = null;
            $error = 'THttpClient: Could not connect to '.$host.$this->uri_;
            throw new TTransportException($error, TTransportException::NOT_OPEN);
        }
    }
}