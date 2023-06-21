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

        if (!$provider = $config->get('speech2text-provider')) {
            $this->log(__FUNCTION__ . ': Provider not selected', LOG_WARN);
            return;
        }

        $message = $ticket->getLastMessage();
        if (!$message || $message->getNumAttachments() == 0) {
            $this->log(__FUNCTION__ . ': No attachments');
            return;
        }

        switch ($provider) {
            case 'azure':
                if (!$apiKey = $config->get('speech2text-apikey')) {
                    $this->log(__FUNCTION__ . ': API Key not configured', LOG_WARN);
                    return;
                }
                $location = $config->get('speech2text-location') ?: 'uksouth';
                $url = "https://{$location}.stt.speech.microsoft.com/speech/recognition/conversation/cognitiveservices/v1?language=en-GB";
                break;
            case 'openai':
                if (!$apiKey = $config->get('speech2text-openaikey')) {
                    $this->log(__FUNCTION__ . ': OpenAI API Key not configured', LOG_WARN);
                    return;
                }
                $url = "https://api.openai.com/v1/audio/transcriptions";
                $prompt = $config->get('speech2text-prompt');
                break;
        }

        $poster = $config->get('speech2text-poster') ?: 'SYSTEM';
        $title = $config->get('speech2text-title') ?: 'Speech to Text';

        foreach ($message->getAttachments() as $attachment) {
            if (!in_array($fileType = $attachment->getFile()->getType(), ['audio/wav', 'audio/x-wav'])) {
                $this->log(__FUNCTION__ . ': File type not valid. ' . $fileType);
                continue;
            }
            $note = '';
            $note_title = $title . ': ' . $attachment->getName();

            switch ($provider) {
                case 'azure':
                    $header = array(
                            "Ocp-Apim-Subscription-Key: $apiKey",
                            "Content-Type: $fileType"
                            );
                    $payload = $attachment->getFile()->getData();
                    break;
                case 'openai':
                    $header = array(
                            "Authorization: Bearer $apiKey",
                            'Content-Type: multipart/form-data'
                            );
                    $payload = array(
                            'model' => 'whisper-1',
                            'file' => new CURLStringFile($attachment->getFile()->getData(), $attachment->getName(), $fileType),
                            'prompt' => $prompt
                            );
                    break;
            }

            $curl = curl_init($url);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_HTTPHEADER, $header);
            curl_setopt($curl, CURLOPT_POST, true);
            curl_setopt($curl, CURLOPT_POSTFIELDS, $payload);
            $response = curl_exec($curl);
            curl_close($curl);
            unset($payload);

            if (empty($response)) {
                $note = 'Transcription unsuccessful. (' . curl_errno($curl) . ')';
                $this->log(__FUNCTION__ . ': Transcription unsuccessful. ' . curl_error($curl));
            } else {
                $result = json_decode($response);
                switch ($provider) {
                    case 'azure':
                        if ($result->RecognitionStatus == 'Success') {
                            $note = $result->DisplayText ?: '[NO SPEECH RECOGNISED]';
                            $this->log(__FUNCTION__ . ': Azure Success! Internal Note should be added. ' . print_r($result, true));
                        } else {
                            $note = "Transcription unsuccessful. ({$result->RecognitionStatus})";
                            $this->log(__FUNCTION__ . ': Azure Transcription unsuccessful. ' . print_r($result, true));
                        }
                        break;
                    case 'openai':
                        $note = $result->text ?: '[NO SPEECH RECOGNISED]';
                        $this->log(__FUNCTION__ . ': OpenAI Success! Internal Note should be added. ' . print_r($result, true));
                        break;
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
