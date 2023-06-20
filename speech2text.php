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
        // From class.file.php:
        //
        // function getData()
        //     # XXX: This is horrible, and is subject to php's memory
        //     #      restrictions, etc. Don't use this function!
        //
        // This plugin uses the above function as it works across Attachent Storage configurations
        //   including 'in the database' and 'filesystem'

        $config = $this->getConfig(self::$pluginInstance);

        if (!$apiKey = $config->get('speech2text-apikey')) {
            $this->log(__FUNCTION__ . ': API Key not configured', LOG_WARN);
            return;
        }

        $message = $ticket->getLastMessage();
        if (!$message || $message->getNumAttachments() == 0) {
            $this->log(__FUNCTION__ . ': No attachments');
            return;
        }

        $poster = $config->get('speech2text-poster') ?: 'SYSTEM';
        $title = $config->get('speech2text-title') ?: 'Speech to Text';
        $location = $config->get('speech2text-location') ?: 'uksouth';
        $msSpeechUrl = "https://{$location}.stt.speech.microsoft.com/speech/recognition/conversation/cognitiveservices/v1?language=en-GB";

        foreach ($message->getAttachments() as $attachment) {
            if (!in_array($fileType = $attachment->getFile()->getType(), ['audio/wav', 'audio/x-wav'])) {
                $this->log(__FUNCTION__ . ': File type not valid. ' . $fileType);
                continue;
            }
            $note = '';
            $note_title = $title . ': ' . $attachment->getName();

            $header = array(
                    "Ocp-Apim-Subscription-Key: $apiKey",
                    "Content-Type: $fileType"
                    );
            $payload = $attachment->getFile()->getData();
            $curl = curl_init($msSpeechUrl);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_HTTPHEADER, $header);
            curl_setopt($curl, CURLOPT_POST, true);
            curl_setopt($curl, CURLOPT_POSTFIELDS, $payload);
            $response = curl_exec($curl);
            if (empty($response)) {
                $note = 'Transcription unsuccessful. (' . curl_errno($curl) . ')';
                $this->log(__FUNCTION__ . ': Transcription unsuccessful. ' . curl_error($curl));
            } else {
                $result = json_decode($response);
                if ($result->RecognitionStatus == 'Success') {
                    $note = $result->DisplayText ?: '[NO SPEECH RECOGNISED]';
                    $this->log(__FUNCTION__ . ': Success! Internal Note should be added');
                } else {
                    $note = "Transcription unsuccessful. ({$result->RecognitionStatus})";
                    $this->log(__FUNCTION__ . ': Transcription unsuccessful. ' . print_r($result, true));
                }
            }
            $ticket->logNote($note_title, $note, $poster, false);
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
