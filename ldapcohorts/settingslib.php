<?php

defined('MOODLE_INTERNAL') || die();

class admin_setting_ldapcohort_trim_lower extends admin_setting_configtext 
{
    /* @var boolean whether to lowercase the value or not before writing in to the db */
    private $lowercase;

    /**
     * Constructor: uses parent::__construct
     *
     * @param string $name unique ascii name, either 'mysetting' for settings that in config, or 'myplugin/mysetting' for ones in config_plugins.
     * @param string $visiblename localised
     * @param string $description long localised info
     * @param string $defaultsetting default value for the setting
     * @param boolean $lowercase if true, lowercase the value before writing it to the db.
     */
    public function __construct($name, $visiblename, $description, $defaultsetting, $lowercase=false) {
        $this->lowercase = $lowercase;
        parent::__construct($name, $visiblename, $description, $defaultsetting);
    }

    /**
     * Saves the setting(s) provided in $data
     *
     * @param array $data An array of data, if not array returns empty str
     * @return mixed empty string on useless data or success, error string if failed
     */
    public function write_setting($data) {
        if ($this->paramtype === PARAM_INT and $data === '') {
            // do not complain if '' used instead of 0
            $data = 0;
        }

        // $data is a string
        $validated = $this->validate($data);
        if ($validated !== true) {
            return $validated;
        }
        if ($this->lowercase) {
            $data = core_text::strtolower($data);
        }
        return ($this->config_write($this->name, trim($data)) ? '' : get_string('errorsetting', 'admin'));
    }
}
