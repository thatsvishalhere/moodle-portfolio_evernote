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

require_once($CFG->libdir.'/portfolio/plugin.php');
require_once($CFG->libdir . '/oauthlib.php');

// To allow coexistence of any other plugin having Evernote API.
if (!isset($GLOBALS['THRIFT_ROOT'])) {
    $GLOBALS['THRIFT_ROOT'] = __DIR__ . '/lib/evernote';
}
if (!class_exists('TException')) {
    require_once($GLOBALS['THRIFT_ROOT'] . '/Thrift.php');
}
if (!class_exists('THttpClient')) {
    require_once($GLOBALS['THRIFT_ROOT'] . '/transport/THttpClient.php');
}
if (!class_exists('TBinaryProtocol')) {
    require_once($GLOBALS['THRIFT_ROOT'] . '/protocol/TBinaryProtocol.php');
}
if (!class_exists('\EDAM\NoteStore\NoteStoreClient')) {
    require_once($GLOBALS['THRIFT_ROOT'] . '/packages/NoteStore/NoteStore.php');
}
if (!class_exists('\EDAM\UserStore\UserStore')) {
    require_once($GLOBALS['THRIFT_ROOT'] . '/packages/UserStore/UserStore.php');
}
if (!class_exists('\EDAM\Types\NoteSortOrder')) {
    require_once($GLOBALS['THRIFT_ROOT'] . '/packages/Types/Types_types.php');
}
if (!class_exists('Moodle_THttpClient')) {
    require_once( __DIR__ .'/lib/Moodle_THttpClient.php');
}

// Import the classes that we're going to be using.
use EDAM\Error\EDAMSystemException,
    EDAM\Error\EDAMUserException,
    EDAM\Error\EDAMErrorCode,
    EDAM\Error\EDAMNotFoundException;

// To use the main Evernote API interface.
use Evernote\Client;

// Import Note class.
use EDAM\Types\Note;
use EDAM\Types\NoteAttributes;
use EDAM\NoteStore\NoteStoreClient;

// Import the Resources Classes for attachments.
use EDAM\Types\Resource;
use EDAM\Types\ResourceAttributes;
use EDAM\Types\Data;

// Import Userstore.
use EDAM\Userstore\UserStoreClient;

/**
 * Portfolio class to access/add notes to the Evernote accounts.
 *
 * This class uses the Evernote API in the library to access and then
 * Add to the Evernote accounts of the users after modifying the contents for the
 * Evernote Markup Language.
 *
 * @package    portfolio_evernote
 * @category   portfolio
 * @copyright  2013 Vishal Raheja
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class portfolio_plugin_evernote extends portfolio_plugin_push_base {

    /**
     * URL to the API production services.
     * @var string
     */
    const API_PROD = 'https://www.evernote.com';

    /**
     * URL to the API development services.
     * @var string
     */
    const API_DEV = 'https://sandbox.evernote.com';

    /**
     * Prefix for the user preferences.
     * @var string
     */
    const SETTING_PREFIX = 'portfolio_evernote_';

    /**
     * URL to the API.
     * @var string
     */
    protected $api = self::API_PROD;

    /**
     * The oauth_helper for oauth authentication of Evernote.
     * @var oauth_helper
     */
    protected $oauth;

    /**
     * Note store URL retrieved after the user authenticates the application.
     * @var string
     */
    protected $notestoreurl = null;

    /**
     * Notestore of the user.
     * @var NoteStoreClient
     */
    protected $notestore;

    /**
     * Token received after a valid OAuth authentication.
     * @var string
     */
    protected $accesstoken = null;

    /**
     * The evernote markup language content that is to be shown in the notebook.
     * @var string
     */
    protected $enmlcontent = "";

    /**
     * Notebooks in the Evernote account of the user.
     * @var array
     */
    protected $notebookarray;

    /**
     * The user's default notebook guid.
     * @var string
     */
    protected $defaultnotebookguid;

    /**
     * Sandbox flag. Turn it to false for production.
     * @var boolean
     */
    protected $sandboxflag = true;

    /**
     * Authorization URL of evernote.
     * @var string
     */
    protected $authorizeurl;

    /**
     * Resource array of the note
     * @var boolean
     */
    protected $resourcearray = array();

    /**
     * Evernote Username of the user
     * @var string
     */
    protected $evernoteuser;

    /**
     * Constructor
     *
     * @param int $instanceid id of plugin instance to construct
     * @param mixed $record stdclass object or named array - use this if you already have the record to avoid another query
     */
    public function __construct($instanceid, $record=null) {
        parent::__construct($instanceid, $record);

        $this->accesstoken = get_user_preferences(self::SETTING_PREFIX.'accesstoken', null);
        $this->notestoreurl = get_user_preferences(self::SETTING_PREFIX.'notestoreurl', null);
    }

    public static function allows_multiple_exports() {
        // Since this plugin needs authentication from external sources, we have to disable this.
        return false;
    }

    public static function get_name() {
        return get_string('pluginname', 'portfolio_evernote');
    }

    public function has_export_config() {
        // To ensure that the user configures before exporting the package.
        return true;
    }

    public function export_config_form(&$mform) {
        global $CFG;
        $signin = optional_param('signin', 0, PARAM_BOOL);
        $returnurl = new moodle_url('/portfolio/add.php');
        $returnurl->param('id', $this->exporter->get('id'));
        $returnurl->param('sesskey', sesskey());
        $returnurl->param('signin', 1);

        // If the user wants to sign into another account, cancel the export and reset the variables.
        if ($signin) {
            set_user_preference(self::SETTING_PREFIX.'tokensecret', '');
            set_user_preference(self::SETTING_PREFIX.'accesstoken', '');
            set_user_preference(self::SETTING_PREFIX.'notestoreurl', '');
            $returnurl->param('cancel', 1);
            $returnurl->param('cancelsure', 1);
            redirect($returnurl);
        }
        $user = $this->get_userstore()->getUser($this->accesstoken);
        $this->evernoteuser = $user->username;
        $mform->addElement('static', 'plugin_username', get_string('evernoteusernamestring', 'portfolio_evernote'), $this->evernoteuser);
        $mform->addElement('static', 'plugin_signinusername', '', html_writer::link($returnurl, get_string('signinanother', 'portfolio_evernote')));
        $mform->addElement('text', 'plugin_notetitle', get_string('customnotetitlelabel', 'portfolio_evernote'));
        $mform->setType('plugin_notetitle', PARAM_RAW);
        $mform->setDefault('plugin_notetitle', get_string('defaultnotetitle', 'portfolio_evernote'));
        $mform->addElement('text', 'plugin_notetags', get_string('notetagslabel', 'portfolio_evernote'));
        $mform->setType('plugin_notetags', PARAM_TEXT);
        $this->notebookarray = $this->list_notebooks();
        $notebookselect = $mform->addElement('select', 'plugin_notebooks', get_string('notebooklabel', 'portfolio_evernote'), $this->notebookarray);
        $notebookselect->setSelected($this->defaultnotebookguid);
        $strrequired = get_string('required');
        $mform->addRule('plugin_notetitle', $strrequired, 'required', null, 'client');
        $mform->addRule('plugin_notebooks', $strrequired, 'required', null, 'client');
    }

    public function get_export_summary() {
        return array(
            get_string('evernoteusernamestring', 'portfolio_evernote') => $this->evernoteuser,
            get_string('customnotetitlelabel', 'portfolio_evernote') => s($this->get_export_config('notetitle')),
            get_string('notetagslabel', 'portfolio_evernote') => $this->get_export_config('notetags'),
            get_string('notebooklabel', 'portfolio_evernote') => $this->notebookarray[$this->get_export_config('notebooks')]
        );
    }

    public function get_allowed_export_config() {
        return array('notetitle', 'notebooks', 'notetags');
    }

    public function prepare_package() {
        return true;
    }

    public function send_package() {
        $files = $this->exporter->get_tempfiles();
        $exportformat = $this->exporter->get('formatclass');
        foreach ($files as $file) {
            $filecontent = $file->get_content();
            $mimetype = $file->get_mimetype();

            if ($file->get_filepath() == "/") {
                if ($mimetype == 'text/html' && $exportformat != PORTFOLIO_FORMAT_FILE) {
                    $htmlcontents = $filecontent;
                    $this->enmlcontent .= self::getenml($htmlcontents);
                } else {
                    $htmlcontents = "";
                    $this->enmlcontent .= '<br />'. self::getenml($htmlcontents);
                    // Add the file as attachment after this.
                    $md5 = md5($filecontent);
                    $resourceattr = new ResourceAttributes (array(
                    'fileName' => $file->get_filename(),
                    'attachment' => true
                    ));
                    $data = new Data(array('bodyHash'=>$md5, 'body' => $filecontent));
                    $resource = new Resource (array(
                        'data' => $data,
                        'mime' => $mimetype,
                        'attributes' => $resourceattr
                    ));
                    $this->enmlcontent .= "<en-media type=\"$mimetype\" hash=\"$md5\" />";
                    $this->resourcearray[] = $resource;
                }
            } else {
                $md5 = md5($filecontent);
                $resourceattr = new ResourceAttributes (array(
                'fileName' => $file->get_filename(),
                'attachment' => true
                ));
                $data = new Data(array('bodyHash'=>$md5, 'body' => $filecontent));
                $resource = new Resource (array(
                    'data' => $data,
                    'mime' => $mimetype,
                    'attributes' => $resourceattr
                ));
                $this->resourcearray[] = $resource;
            }
        }
        $this->build_attachments();
        $this->create_note();
        $this->resourcearray = null;
        return true;
    }

    // Unsure about it.
    public function get_interactive_continue_url() {
        return false;
    }

    public function expected_time($callertime) {
        // We trust what the portfolio says.
        return $callertime;
    }

    public static function has_admin_config() {
        return true;
    }

    public static function admin_config_form(&$mform) {
        $mform->addElement('static', null, '', get_string('oauthinfo', 'portfolio_evernote'));
        $mform->addElement('selectyesno', 'usedevapi', get_string('usedevapi', 'portfolio_evernote'), 0);
        $mform->addElement('static', '', '', get_string('usedevapi_info', 'portfolio_evernote'));
        $mform->addElement('text', 'consumerkey', get_string('consumerkey', 'portfolio_evernote'));
        $mform->setType('consumerkey', PARAM_RAW_TRIMMED);
        $mform->addElement('text', 'secret', get_string('secret', 'portfolio_evernote'));
        $mform->setType('secret', PARAM_RAW_TRIMMED);
        $strrequired = get_string('required');
        $mform->addRule('consumerkey', $strrequired, 'required', null, 'client');
        $mform->addRule('secret', $strrequired, 'required', null, 'client');
    }

    public static function get_allowed_config() {
        return array('consumerkey', 'secret', 'usedevapi');
    }

    public static function allows_multiple_instances() {
        return false;
    }

    public function supported_formats() {
        return array(PORTFOLIO_FORMAT_RICHHTML, PORTFOLIO_FORMAT_PLAINHTML, PORTFOLIO_FORMAT_FILE);
    }

    public function steal_control($stage) {
        global $CFG;
        if ($stage != PORTFOLIO_STAGE_CONFIG) {
            return false;
        }

        // First stage of oauth from the user configuration.
        $this->get_token_from_config();
    }

    public function post_control($stage, $params) {
        if ($stage != PORTFOLIO_STAGE_CONFIG) {
            return;
        }

        // Second and third (final) steps for oauth authentication.
        // Storing the access credentials for the user.
        $this->get_token_credentials();
    }

    public function instance_sanity_check() {
        $consumerkey = $this->get_config('consumerkey');
        $secret = $this->get_config('secret');

        // If there is no oauth config
        // there will be no config and this plugin should be disabled.
        if (empty($consumerkey) or empty($secret)) {
            return 'nooauthcredentials';
        }
        return 0;
    }

    /**
     * To get the token by verifying the Evernote API Consumer key and Secret
     */
    protected function get_token_from_config() {
        $result = $this->get_oauth()->request_token();
        $this->authorizeurl = $result['authorize_url'];
        try {
            $user = $this->get_userstore()->getUser($this->accesstoken);
        } catch (Exception $e) {
            // If there is an exception or the user has not yet signed in, redirect to the authorization page.
            set_user_preference(self::SETTING_PREFIX.'tokensecret', $result['oauth_token_secret']);
            set_user_preference(self::SETTING_PREFIX.'accesstoken', '');
            redirect($this->authorizeurl);
        }
    }

    /**
     * To get the token for the user after the user has granted access to his Evernote account.
     */
    protected function get_token_credentials() {
        $token  = optional_param('oauth_token', '', PARAM_TEXT);
        $verifier  = optional_param('oauth_verifier', '', PARAM_TEXT);
        $secret = get_user_preferences(self::SETTING_PREFIX.'tokensecret', '');

        // Set the user variables if the user grants access, else reset the values.
        if ($verifier != null) {
            $access = $this->get_oauth()->get_access_token($token, $secret, $verifier);
            $this->test = $access;
            $notestore  = $access['edam_noteStoreUrl'];
            $userid  = $access['edam_userId'];
            $accesstoken  = $access['oauth_token'];
            $this->accesstoken = $accesstoken;
            $this->notestoreurl = $notestore;
            set_user_preference(self::SETTING_PREFIX.'accesstoken', $accesstoken);
            set_user_preference(self::SETTING_PREFIX.'notestoreurl', $notestore);
            set_user_preference(self::SETTING_PREFIX.'userid', $userid);
        } else {
            set_user_preference(self::SETTING_PREFIX.'tokensecret', '');
            throw new portfolio_plugin_exception('nopermission', 'portfolio_evernote');
        }
    }

    /**
     * Create the note from the class variable of enml content.
     */
    protected function create_note() {
        $note = new Note();
        $note->title = $this->get_export_config('notetitle');
        $tags = $this->get_export_config('notetags');
        if ($tags != "") {
            $tagarray = explode(",", $tags);
            for ($i=0; $i<count($tagarray); $i++) {
                $tagarray[$i] = trim($tagarray[$i]);
            }
            $note->tagNames = $tagarray;
        }
        $note->content = $this->enmlcontent;
        $note->resources = $this->resourcearray;
        $note->notebookGuid = $this->get_export_config('notebooks');
        try {
            $notebooks = $this->get_notestore()->createNote($this->accesstoken, $note);
        } catch (Exception $e) {
            throw new portfolio_plugin_exception('failedtocreatenote', 'portfolio_evernote');
        }
    }

    /**
     * To get the list of the user's notebooks.
     * @return array of Evernote Notebooks
     */
    protected function list_notebooks() {
        try {
            $notebooks = $this->get_notestore()->listNotebooks($this->accesstoken);
        } catch (Exception $e) {
            throw new portfolio_plugin_exception('failedlistingnotebooks', 'portfolio_evernote');
        }
        $notebookarray = array();
        if (!empty($notebooks)) {
            foreach ($notebooks as $notebook) {
                if ($notebook->defaultNotebook) {
                    $this->defaultnotebookguid = $notebook->guid;
                    $notebookarray[$notebook->guid] = get_string('denotedefaultnotebook', 'portfolio_evernote', $notebook->name);
                    if (!empty($notebook->stack)) {
                        $notebookarray[$notebook->guid] .= get_string('denotestack', 'portfolio_evernote', $notebook->stack);
                    }
                } else {
                    $notebookarray[$notebook->guid] = $notebook->name;
                    if (!empty($notebook->stack)) {
                        $notebookarray[$notebook->guid] .= get_string('denotestack', 'portfolio_evernote', $notebook->stack);
                    }
                }
            }
        }
        return $notebookarray;
    }

    /**
     * To convert HTML content into ENML content.
     *
     * @param string $htmlcontents HTML string that is needed to convert to ENML.
     * @return string  ENML string of the given HTML string.
     */
    protected static function getenml($htmlcontents) {
        $htmlcontents = strip_tags($htmlcontents, '<a><abbr><acronym><address><area><b><bdo><big><blockquote><br><caption>'.
            '<center><cite><code><col><colgroup><dd><del><dfn><div><dl><dt><em><font><h1><h2><h3><h4><h5><h6><hr><i><img><ins>'.
            '<kbd><li><map><ol><p><pre><q><s><samp><small><span><strike><strong><sub><sup><table><tbody><td><tfoot><th><thead>'.
            '<title><tr><tt><u><ul><var><xmp>');
        $htmlcontents = str_replace("<br/ >", "", $htmlcontents);
        // Removing the disallowed attributes.
        $htmlcontents = '<body>'.$htmlcontents.'</body>';
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->loadHTML('<?xml encoding="UTF-8">'.$htmlcontents);
        libxml_use_internal_errors(false);
        $elements = $dom->getElementsByTagName('body')->item(0);
        $elements = self::reform_style_attribute($elements);
        $htmlcontents = self::get_inner_html($elements);
        $enmlcontents = $htmlcontents;
        return $enmlcontents;
    }

    /**
     * Subsidiary function to get the inner html of the DOM element.
     *
     * @param DOM element $node for which we need the inner html.
     * @return string of the inner html of the DOM element given.
     */
    protected static function get_inner_html( $node ) {
        $innerhtml= '';
        if ($node->childNodes!==null) {
            $children = $node->childNodes;
            foreach ($children as $child) {
                $innerhtml .= $child->ownerDocument->saveXML( $child );
            }
        }
        return $innerhtml;
    }

    /**
     * Subsidiary function to remove all the unsupported attributes but to retain some font attributes from the style attribute.
     *
     * @param DOM element $elements
     * @return DOM element without the unsupported attributes and the necessary font effects retained by modifying the inner html.
     */
    protected static function reform_style_attribute ($elements) {
        if ($elements->childNodes!==null) {
            $numchildnodes = $elements->childNodes->length;
            for ($i=0; $i<$numchildnodes; $i++) {
                $element = self::reform_style_attribute($elements->childNodes->item(0));
                $elements->removeChild($elements->childNodes->item(0));
                $elements->appendChild($element);
            }

            $bannedattributes = array('class', 'id', 'accesskey', 'data', 'dynsrc', 'tabindex');
            foreach ($elements->attributes as $attr) {
                $attrname = strtolower($attr->nodeName);

                // Removing all the banned attributes along with
                // all the attributes starting with 'on'.
                if (in_array($attrname, $bannedattributes) || strpos($attrname, 'on') === 0) {
                    $elements->removeAttribute($attr->nodeName);
                }
            }
        }
        return $elements;
    }

    /**
     * Get the OAuth object.
     *
     * @return oauth_helper object.
     */
    protected function get_oauth() {
        if (empty($this->oauth)) {
            $test = $this->get_config('usedevapi');
            if (!empty($test)) {
                $this->api = self::API_DEV;
            }
            $callbackurl = new moodle_url('/portfolio/add.php', array(
                    'postcontrol' => '1',
                    'type' => 'evernote'
                ));
            $args['oauth_consumer_key'] = $this->get_config('consumerkey');
            $args['oauth_consumer_secret'] = $this->get_config('secret');
            $args['oauth_callback'] = $callbackurl->out(false);
            $args['api_root'] = $this->api;
            $args['request_token_api'] = $this->api . '/oauth';
            $args['access_token_api'] = $this->api . '/oauth';
            $args['authorize_url'] = $this->api . '/OAuth.action';
            $this->oauth = new oauth_helper($args);
        }
        return $this->oauth;
    }

    /**
     * Return the object to call Evernote's API
     *
     * @return NoteStoreClient object
     */
    protected function get_notestore() {
        if (empty($this->notestore)) {
            $parts = parse_url($this->notestoreurl);
            if (!isset($parts['port'])) {
                if ($parts['scheme'] === 'https') {
                    $parts['port'] = 443;
                } else {
                    $parts['port'] = 80;
                }
            }
            $notestorehttpclient = new Moodle_THttpClient($parts['host'], $parts['port'], $parts['path'], $parts['scheme']);
            $notestoreprotocol = new TBinaryProtocol($notestorehttpclient);
            $this->notestore = new NoteStoreClient($notestoreprotocol, $notestoreprotocol);
        }
        return $this->notestore;
    }

    /**
     * Return the userstore of the user to get the username
     *
     * @return UserStoreClient object
     */
    protected function get_userstore() {
        $usedevflag = $this->get_config('usedevapi');
        if (!empty($usedevflag)) {
            $this->api = self::API_DEV;
        }
        $url = $this->api."/edam/user";
        if (empty($this->userstore)) {
            $parts = parse_url($url);
            if (!isset($parts['port'])) {
                if ($parts['scheme'] === 'https') {
                    $parts['port'] = 443;
                } else {
                    $parts['port'] = 80;
                }
            }
            $userstorehttpclient = new Moodle_THttpClient($parts['host'], $parts['port'], $parts['path'], $parts['scheme']);
            $userstoreprotocol = new TBinaryProtocol($userstorehttpclient);
            $this->userstore = new UserStoreClient($userstoreprotocol, $userstoreprotocol);
        }
        return $this->userstore;
    }

    /**
     * Build the attachments on to the current enml code and finalize the content string
     */
    protected function build_attachments() {
        $filenames = array();
        if (!empty($this->resourcearray)) {
            foreach ($this->resourcearray as $attachresource) {
                $filenames[] = 'site_files/'.$attachresource->attributes->fileName;
            }
            $htmlcontents = '<body>'.$this->enmlcontent.'</body>';
            libxml_use_internal_errors(true);
            $dom = new DOMDocument();
            $dom->loadHTML('<?xml encoding="UTF-8">'.$htmlcontents);
            libxml_use_internal_errors(false);
            $elements = $dom->getElementsByTagName('body')->item(0);
            $elements = $this->reform_attachments($dom, $elements,  $filenames);
            $htmlcontents = self::get_inner_html($elements);
            $this->enmlcontent = '<?xml version="1.0" encoding="UTF-8"?>' .
                '<!DOCTYPE en-note SYSTEM "http://xml.evernote.com/pub/enml2.dtd">' .
                '<en-note>'.$htmlcontents.'</en-note>';
        } else {
            $this->enmlcontent = '<?xml version="1.0" encoding="UTF-8"?>' .
                '<!DOCTYPE en-note SYSTEM "http://xml.evernote.com/pub/enml2.dtd">' .
                '<en-note>'.$this->enmlcontent.'</en-note>';
        }
    }

    /**
     * Replace the embedded objects in enml and replace them with enml element
     * with the required enml attributes
     *
     * @param DOMDocument $dom the main dom in which the enml code has been extracted from
     * @param DOMElement $elements the element in which the embedded code needs to be introduced
     * @param array $filenames the array in which the file names of the attachments are stored
     * @return UserStoreClient object
     */
    protected function reform_attachments ($dom, $elements, $filenames) {
        if (!empty($filenames)) {
            if ($elements->childNodes!==null) {
                $numchildnodes = $elements->childNodes->length;
                for ($i=0; $i<$numchildnodes; $i++) {
                    $element = $this->reform_attachments($dom, $elements->childNodes->item(0), $filenames);
                    $elements->removeChild($elements->childNodes->item(0));
                    $elements->appendChild($element);
                }
            }
            $tempelement = null;
            if ($elements->attributes != null) {
                foreach ($elements->attributes as $attr) {
                    $attrvalue = $attr->value;

                    if (in_array($attrvalue, $filenames)) {
                        $index = array_search($attrvalue, $filenames);
                        $resource = $this->resourcearray[$index];
                        $newelement = $dom->createElement('en-media');
                        $typeattribute = $dom->createAttribute('type');
                        $typeattribute->value = $resource->mime;
                        $hashattribute = $dom->createAttribute('hash');
                        $hashattribute->value = $resource->data->bodyHash;
                        $newelement->appendChild($typeattribute);
                        $newelement->appendChild($hashattribute);
                        $elements->parentNode->replaceChild($newelement, $elements);
                        $tempelement = $newelement;
                        break;
                    }
                }
            }
            if ($tempelement != null) {
                $elements = $tempelement;
            }
        }
        return $elements;
    }
}