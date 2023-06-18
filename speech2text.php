<?php

require_once(INCLUDE_DIR . 'class.plugin.php');
require_once(INCLUDE_DIR . 'class.signal.php');
require_once(INCLUDE_DIR . 'class.ticket.php');
require_once(INCLUDE_DIR . 'class.osticket.php');
require_once(INCLUDE_DIR . 'class.config.php');
require_once('config.php');

class Speech2TextPlugin extends Plugin
{
    public $config_class = "Speech2TextPluginConfig";

    private static $pluginInstance = null;

    private function getPluginInstance(?int $id)
    {
        if ($id && ($i = $this->getInstance($id))) {
            return $i;
        }

        return $this->getInstances()->first();
    }

    public function bootstrap()
    {
        self::$pluginInstance = self::getPluginInstance(null);

        Signal::connect('ticket.created', [$this, 'processAttachments']);
    }

    public function processAttachments($ticket)
    {
        $config = $this->getConfig(self::$pluginInstance);

        $title = $config->get('speech2text-title') ?: 'Speech to Text';
        $poster = $config->get('speech2text-poster') ?: 'SYSTEM';
        $note = '';
        $message = $ticket->getLastMessage();
        if ($message && $message->getNumAttachments() > 0) {
            $attachments = array();
            foreach ($message->getAttachments() as $attachment) {
                if (in_array($fileType = $attachment->getFile()->getType(), ['audio/wav'])) {
                    $title .= ': ' . $attachment->getName();
                    $location = $config->get('speech2text-location') ?: 'uksouth';
                    $msSpeechUrl = "https://{$location}.stt.speech.microsoft.com/speech/recognition/conversation/cognitiveservices/v1?language=en-GB";
                    $apiKey = $config->get('speech2text-apikey');
                    if (!empty($apiKey)) {
                        // From class.file.php:
                        // function getData() {
                        //     # XXX: This is horrible, and is subject to php's memory
                        //     #      restrictions, etc. Don't use this function!
                        $att = $attachment->getFile()->getData();
                        $opts = array('http' =>
                            array(
                                'method' => 'POST',
                                'header' => array(
                                    "Ocp-Apim-Subscription-Key: $apiKey",
                                    "Content-Type: $fileType"
                                ),
                                'content' => $att
                            )
                        );
                        $context = stream_context_create($opts);
                        $note = file_get_contents($msSpeechUrl, false, $context);
                        if (!empty($note)) {
                            $this->log(__FUNCTION__ . ': Success, Internal Note should be added');
                        } else {
                            $note = "Transcription unsuccessful. ({$http_response_header[0]})";
                            $this->log(__FUNCTION__ . ': Transcription unsuccessful. ' . print_r($http_response_header, true));
                        }
                        $ticket->logNote($title, $note, $poster, false);
                    } else {
                        $this->log(__FUNCTION__ . ': API Key not configured', LOG_WARN);
                    }
                }
            }
        } else {
            $this->log(__FUNCTION__ . ': No attachments');
        }
    }

    private function log($message, $level = LOG_DEBUG)
    {
        global $ost;

        if ($ost instanceof osTicket && $message) {
            $ost->log($level, "Plugin: Speech2Text", $message);
        }
    }
}
