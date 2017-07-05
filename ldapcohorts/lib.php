<?php

defined('MOODLE_INTERNAL') || die();

class enrol_ldapcohorts_plugin extends enrol_plugin
{

    private $_cohorts_added = 0;
    private $_cohorts_existing = 0;
    private $_users_added = 0;
    private $_users_existing = 0;

    /**
     * cohorts that will get synchronize
     * @var array
     */
    private $_cohorts = array();

    protected $ldapconnection = null;

    /**
     * connect to ldap server using the config settings
     */
    public function ldap_connect()
    {
        global $CFG;
        require_once($CFG->libdir . '/ldaplib.php');

        if (!empty($this->ldapconnection)) {
            return true;
        }

        if ($ldapconnection = ldap_connect_moodle($this->get_config('host_url'), $this->get_config('ldap_version'),
            $this->get_config('user_type'), $this->get_config('bind_dn'),
            $this->get_config('bind_pw'), $this->get_config('user_deref'),
            $debuginfo, $this->get_config('start_tls'))
        ) {
            $this->ldapconnection = $ldapconnection;
            return true;
        }

        exit(get_string('auth_ldap_noconnect_all', 'enrol_ldapcohorts') . "\n DEBUG: " . $debuginfo);
    }

    /**
     * cronjob will be required if the options for this are set
     *
     * @return bool
     */
    public function is_cron_required()
    {
        $_enabled = intval($this->get_config('cron_enabled'));

        return $_enabled == 1 ? true : false;
    }

    /**
     * synchronize the cohorts (LDAP user groups)
     */
    public function sync_cohorts()
    {
        mtrace(get_string('connectingldap', 'enrol_ldapcohorts'));
        @ob_flush();
        flush();

        $this->ldap_connect();

        mtrace(get_string('synchronizing_cohorts', 'enrol_ldapcohorts'));
        @ob_flush();
        flush();

        global $CFG, $DB;

        require_once("{$CFG->dirroot}/cohort/lib.php");

        $ldapconnection = $this->ldapconnection;

        $wanted_fields = array();
        if (!empty($this->config->cohort_name)) {
            array_push($wanted_fields, $this->config->cohort_name);
        }

        if (!empty($this->config->cohort_idnumber)) {
            array_push($wanted_fields, $this->config->cohort_idnumber);
        }

        if (!empty($this->config->cohort_description)) {
            array_push($wanted_fields, $this->config->cohort_description);
        }

        if (empty($this->config->user_member_attribute)) {
            mtrace(get_string('err_member_attribute', 'enrol_ldapcohorts'));
            return;
        }

        array_push($wanted_fields, $this->get_config('cohort_member_attribute', 'member'));

        //contexts for searching cohorts
        $contexts = explode(';', $this->config->cohort_contexts);

        $filter = '(&(' . $this->config->cohort_name . '=*)' . $this->config->cohort_objectclass . ')';

        foreach ($contexts as $context) {
            $context = trim($context);
            if (empty($context)) {
                continue;
            }

            if ($this->config->cohort_search_sub) {
                //use ldap_search to find first user from subtree
                $ldap_result = ldap_search($ldapconnection, $context,
                    $filter,
                    $wanted_fields);
            } else {
                //search only in this context
                $ldap_result = ldap_list($ldapconnection, $context,
                    $filter,
                    $wanted_fields);
            }

            if (!$ldap_result) {
                continue;
            }

            $records = $this->_flatten(ldap_get_entries($ldapconnection, $ldap_result));

            foreach ($records as $cohort) {
                $cohort = array_change_key_case($cohort, CASE_LOWER);

                if (empty($cohort[$this->config->cohort_name][0])) {
                    mtrace(get_string('err_invalid_cohort_name', 'enrol_ldapcohorts', $this->config->cohort_name));
                    @ob_flush();
                    flush();
                    continue;
                }

                if (empty($cohort[$this->config->cohort_idnumber][0])) {
                    mtrace(get_string('err_invalid_cohort_idnumber', 'enrol_ldapcohorts', $this->config->cohort_idnumber));
                    @ob_flush();
                    flush();
                    continue;
                }

                $cohortname = strtoupper($cohort[$this->config->cohort_name][0]);

                $moodle_cohort = $DB->get_record('cohort', array('name' => $cohortname));
                if (empty($moodle_cohort)) {
                    if (false != ($cohortid = $this->create_cohort($cohort))) {
                        $moodle_cohort = $DB->get_record('cohort', array('id' => $cohortid));
                        mtrace(get_string('cohort_created', 'enrol_ldapcohorts', $moodle_cohort->name));
                        $this->_cohorts_added++;
                    }
                } else {
                    if (strpos($moodle_cohort->description, '<strong>[LDAP Cohort Sync]</strong>') === false) {
                        $moodle_cohort->description = '<strong>[LDAP Cohort Sync]</strong> ' . $moodle_cohort->description;
                        $DB->update_record('cohort', $moodle_cohort);
                    }
                    $this->_cohorts_existing++;
                    mtrace(get_string('cohort_existing', 'enrol_ldapcohorts', $moodle_cohort->name));
                }
                @ob_flush();
                flush();

                if (empty($moodle_cohort->id)) {
                    mtrace(get_string('err_create_cohort', 'enrol_ldapcohorts', $cohortname));
                    continue;
                }

                $this->_cohorts [$moodle_cohort->idnumber] = $moodle_cohort;

                if (!empty($cohort[$this->config->cohort_member_attribute])) {
                    $membership = $cohort[$this->config->cohort_member_attribute];
                    if (is_array($membership) && isset($membership['count'])) {
                        $membership = $this->_flatten($membership);
                    } else {
                        $membership = array($cohort[$this->config->cohort_member_attribute]);
                    }
                    $this->sync_users($moodle_cohort, $membership);
                }
            }
        }
        mtrace(get_string('synchronized_cohorts', 'enrol_ldapcohorts', $this->_cohorts_added + $this->_cohorts_existing));
    }

    /**
     * synchronize users from a cohort
     *
     * @param $moodle_cohort
     * @param array $uid_in
     */
    public function sync_users($moodle_cohort, $uid_in = array())
    {
        if (empty($uid_in)) {
            return;
        }
        mtrace(get_string('cohort_sync_users', 'enrol_ldapcohorts'), "");
        @ob_flush();
        flush();
        global $CFG, $DB;
        $ldapconnection = $this->ldapconnection;

        $count = 0;
        //contexts for searching users
        $contexts = explode(';', $this->config->user_contexts);
        $user_filter = '(&(' . $this->config->user_attribute . '=*)(|';
        foreach ($uid_in as $uid) {
            $user_filter .= '(' . $this->config->user_member_attribute . '=' . $uid . ')';
        }
        $user_filter .= ')' . $this->config->user_objectclass . ')';

        foreach ($contexts as $context) {
            $context = trim($context);
            if (empty($context)) {
                continue;
            }

            if ($this->config->user_search_sub) {
                //use ldap_search to find first user from subtree
                $ldap_result = ldap_search($ldapconnection, $context,
                    $user_filter);
            } else {
                //search only in this context
                $ldap_result = ldap_list($ldapconnection, $context,
                    $user_filter);
            }

            if (!$ldap_result) {
                continue;
            }

            $ldap_users = ldap_get_entries_moodle($ldapconnection, $ldap_result);

            foreach ($ldap_users as $i => $ldap_user) {
                $ldap_user = array_change_key_case($ldap_user, CASE_LOWER);

                if (empty($ldap_user['uid'][0])) {
                    mtrace("\t" . get_string('err_user_empty_uid', 'enrol_ldapcohorts', $ldap_user['cn'][0]));
                    @ob_flush();
                    flush();
                    continue;
                }

				$username_termination = $this->get_config('username_termination');
                $moodle_user = $DB->get_record('user', array('username' => $ldap_user['uid'][0].trim($username_termination)));
                if (empty($moodle_user)) {
                    if (false != ($userid = $this->create_user($ldap_user))) {
                        $moodle_user = $DB->get_record('user', array('id' => $userid));
                        $this->_users_added++;
                    }
                } else {
                    $this->_users_existing++;
                }

                if (empty($moodle_user->id)) {
                    mtrace("\t" . get_string('err_create_user', 'enrol_ldapcohorts', $ldap_user['uid'][0]));
                    @ob_flush();
                    flush();
                    continue;
                }

                try {
                    cohort_add_member($moodle_cohort->id, $moodle_user->id);
                } catch (Exception $e) {
                    mtrace("\t" . get_string('err_user_exists_in_cohort', 'enrol_ldapcohorts', array('cohort' => $moodle_cohort->name, 'user' => $ldap_user['uid'][0])));
                    @ob_flush();
                    flush();
                }
                $count++;
            }
        }
        mtrace(get_string('user_synchronized', 'enrol_ldapcohorts', array('count' => $count, 'cohort' => $moodle_cohort->name)));
        @ob_flush();
        flush();
    }

    public function create_user($ldap_user)
    {
        global $CFG, $DB;

        $user = new stdClass();
        
		$username_termination = $this->get_config('username_termination');
		$user->username = trim(core_text::strtolower($ldap_user['uid'][0])).trim($username_termination);
		
        $values = array(
            'givenname' => 'firstname',
            'sn' => 'lastname',
            'mail' => 'email',
            'logindisabled' => 'suspended',
            'description' => 'description'
        );

        if ($this->config->user_idnumber) {
            $values[$this->config->user_idnumber] = 'idnumber';
        }

        //TODO: should these be configurable ?
        foreach ($values as $ldap_key => $moodle_field) {
            if (isset($ldap_user[$ldap_key])) {
                if (is_array($ldap_user[$ldap_key])) {
                    $newval = core_text::convert($ldap_user[$ldap_key][0], $this->config->ldapencoding, 'utf-8');
                } else {
                    $newval = core_text::convert($ldap_user[$ldap_key], $this->config->ldapencoding, 'utf-8');
                }
                $user->{$moodle_field} = $newval;
            }
        }

        // Prep a few params
        $user->timecreated = $user->timemodified = time();
        $user->confirmed = 1;
        $user->auth = 'ldap';
        $user->mnethostid = $CFG->mnet_localhost_id;

        if (isset($user->suspended)) {
            $_s = strtolower(trim($user->suspended));
            if ($_s == 'false') {
                $_s = 0;
            } elseif ($_s == 'true') {
                $_s = 1;
            }
            $user->suspended = $_s;
        } else {
            $user->suspended = 0;
        }

        if (empty($user->lang)) {
            $user->lang = $CFG->lang;
        }
        try {
            $id = $DB->insert_record('user', $user);
        } catch (Exception $e) {
            mtrace("\n\t Error creating user: " . $e->getMessage());
        }
        mtrace("\n\t" . get_string('user_dbinsert', 'enrol_ldapcohorts', array('name' => $user->username, 'id' => $id)));
        @ob_flush();
        flush();

        return $id;
    }

    /**
     * create new moodle cohort based on LDAP entry
     *
     * @param $ldap_entry
     * @return bool|int
     */
    public function create_cohort($ldap_entry)
    {
        $cohort = new stdClass();

        $cohort->idnumber = isset ($ldap_entry[$this->config->cohort_idnumber][0]) ? $ldap_entry[$this->config->cohort_idnumber][0] : 0;
        $cohort->name = isset ($ldap_entry[$this->config->cohort_name][0]) ? $ldap_entry[$this->config->cohort_name][0] : '';
        $cohort->description = isset ($ldap_entry[$this->config->cohort_description][0]) ? $ldap_entry[$this->config->cohort_description][0] : '';

        $cohort->description = '<strong>[LDAP Cohort Sync]</strong> ' . $cohort->description;

        $cohort->contextid = $this->config->context;

        if (empty($cohort->idnumber) || empty($cohort->name)) {
            return false;
        }

        return cohort_add_cohort($cohort);

    }

    public function cron($sendEmail = true)
    {
        $this->load_config();

        echo "-----------------------------\n";
        $this->sync_cohorts();

        echo "-----------------------------\n";

        if ($sendEmail === true && (!empty($this->config->email_report_enabled) && !empty($this->config->email_report))) {
            //send email just in case something new was added
            if ($this->_cohorts_added || $this->_users_added) {
                $this->send_report_email();
            }
        }
    }

    public function send_report_email()
    {
        global $CFG;

        if (!empty($CFG->noemailever)) {
            // hidden setting for development sites, set in config.php if needed
            mtrace('Error: lib/moodlelib.php email_to_user(): Not sending email due to noemailever config setting');
            return true;
        }

        $mail = get_mailer();

        $supportuser = core_user::get_support_user();

        $mail->Sender = $supportuser->email;
        $mail->From = $CFG->noreplyaddress;
        $mail->FromName = $supportuser->firstname;

        $mail->Subject = get_string('report_email_subject', 'enrol_ldapcohorts');

        $mail->WordWrap = 79; // set word wrap

        $messagehtml = get_string('report_email_html', 'enrol_ldapcohorts', array('ca' => $this->_cohorts_added, 'ce' => $this->_cohorts_existing, 'ua' => $this->_users_added, 'ue' => $this->_users_existing));
        $messagetext = get_string('report_email_text', 'enrol_ldapcohorts', array('ca' => $this->_cohorts_added, 'ce' => $this->_cohorts_existing, 'ua' => $this->_users_added, 'ue' => $this->_users_existing));
        $mail->IsHTML(true);
        $mail->Encoding = 'quoted-printable'; // Encoding to use
        $mail->Body = $messagehtml;
        $mail->AltBody = "\n$messagetext\n";

        $mail->AddAddress($this->config->email_report);

        if ($mail->Send()) {
            $mail->IsSMTP(); // use SMTP directly
            return true;
        } else {
            mtrace('ERROR: ' . $mail->ErrorInfo);
            return false;
        }
    }

    /**
     * retreive the actual values from a ldap result array
     *
     * @param array $entry with a field for count and following fields are the actual needed array values
     * @return array the values extracted from the array
     */
    private function _flatten($entry)
    {
        if (!is_array($entry) || empty($entry['count'])) {
            return array();
        }

        unset($entry['count']);
        return array_values($entry);
    }
}

function get_category_options()
{

    $displaylist = coursecat::make_categories_list('moodle/cohort:manage');
//    make_categories_list($displaylist, $parentlist, 'moodle/cohort:manage');
    $options = array();

    $syscontext = context_system::instance();
    if (has_capability('moodle/cohort:manage', $syscontext)) {
        $options[$syscontext->id] = $syscontext->get_context_name();
    }
    foreach ($displaylist as $cid => $name) {
        $context = context_coursecat::instance($cid);
        $options[$context->id] = $name;
    }

    return $options;
}

function enrol_ldapcohorts_supports($feature)
{
    return null;
}