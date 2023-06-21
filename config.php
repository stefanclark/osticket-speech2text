<?php

require_once INCLUDE_DIR . 'class.plugin.php';

class Speech2TextPluginConfig extends PluginConfig
{
    public function translate()
    {
        if (!method_exists('Plugin', 'translate')) {
            return array(
                function ($x) {
                    return $x;
                }
                ,
                function ($x, $y, $n) {
                    return $n != 1 ? $y : $x;
                }
                ,
            );
        }
        return Plugin::translate('speech2text');
    }

    public function getOptions()
    {
        list ($__, $_N) = self::translate();
        return array(
            'speech2text' => new SectionBreakField(array(
                'label' => 'Speech to Text Plugin',
            )),
            'speech2text-title' => new TextboxField(array(
                'label' => $__('Note Title'),
                'hint' => $__('Summary of the note (optional)')
            )),
            'speech2text-poster' => new TextboxField(array(
                'label' => $__('Note Poster'),
                'hint' => $__('Name of Note Poster (optional - defaults to "SYSTEM")')
            )),
            'speech2text-provider' => new ChoiceField(array(
                'label' => $__('Transcription Provider'),
                'choices' => array(
                    'azure' => 'Azure Speech services',
                    'openai' => 'OpenAI Whisper'
                )
            )),
            'speech2text-location' => new TextboxField(array(
                'label' => $__('Azure Server Location'),
                'hint' => $__('Location of azure speech resource, e.g. uksouth')
            )),
            'speech2text-apikey' => new TextboxField(array(
                'label' => $__('Speech service API Key'),
                'hint' => $__('Available from Azure portal'),
                'configuration' => array(
                    'size' => 32,
                    'length' => 32
                )
            )),
            'speech2text-openaikey' => new TextboxField(array(
                'label' => $__('OpenAI API Key'),
                'hint' => $__('Available from OpenAI'),
                'configuration' => array(
                    'size' => 52,
                    'length' => 52
                )
            )),
            'speech2text-prompt' => new TextareaField(array(
                'label' => $__('Whisper prompt'),
                'hint' => $__('An optional text to guide the model\'s style'),
                'configuration' => array(
                    'html' => true,
                    'size' => 'small',
                )
            )),
        );
    }
}
