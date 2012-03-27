<?php

defined('MOODLE_INTERNAL') || die();

if ($ADMIN->fulltree) {
    error_reporting(E_ALL);
    $__do = optional_param('__do', null, PARAM_ALPHA);
    
    if (null !== $__do && $__do == 'ldapcohortssync') {
        require_once(dirname(__FILE__) . '/sync.php');
        exit();
    }
    
    require_once(dirname(__FILE__) . '/settingslib.php');
    
    $run_sync = '<a target="_blank" href="' . $CFG->wwwroot . '/admin/settings.php?section=enrolsettingsldapcohorts&amp;__do=ldapcohortssync"><strong style="font-size: 110%">' . get_string('here', 'enrol_ldapcohorts') . '</strong></a>';
    
    //--- heading ---
    $settings->add(new admin_setting_heading('enrol_ldapcohorts_settings', '', get_string('pluginname_desc', 'enrol_ldapcohorts', $run_sync)));
    
    if (!function_exists('ldap_connect')) {
        $settings->add(new admin_setting_heading('enrol_phpldapcohorts_noextension', '', get_string('phpldap_noextension', 'enrol_ldapcohorts')));
    } else {
        require_once($CFG->libdir.'/ldaplib.php');
        require_once(dirname(__FILE__).'/lib.php');

        $yesno = array(get_string('no'), get_string('yes'));
        
        //--- general settings ---
        $settings->add(new admin_setting_heading('enrol_ldapcohorts_general_settings', get_string('general_settings', 'enrol_ldapcohorts'), ''));
        $settings->add(new admin_setting_configselect('enrol_ldapcohorts/cron_enabled', get_string('cron_enabled_key', 'enrol_ldapcohorts'), get_string('cron_enabled', 'enrol_ldapcohorts', $run_sync), 1, $yesno));
        $settings->add(new admin_setting_configselect('enrol_ldapcohorts/email_report_enabled', get_string('email_report_enabled_key', 'enrol_ldapcohorts'), get_string('email_report_enabled', 'enrol_ldapcohorts'), 1, $yesno));
        $settings->add(new admin_setting_ldapcohort_trim_lower('enrol_ldapcohorts/email_report', get_string('email_report_key', 'enrol_ldapcohorts'), get_string('email_report', 'enrol_ldapcohorts'), '', true));
        
        
        //--- connection settings ---
        $settings->add(new admin_setting_heading('enrol_ldap_cohort_server_settings', get_string('server_settings', 'enrol_ldapcohorts'), ''));
        $settings->add(new admin_setting_configtext('enrol_ldapcohorts/host_url', get_string('host_url_key', 'enrol_ldapcohorts'), get_string('host_url', 'enrol_ldapcohorts'), ''));
        // Set LDAPv3 as the default. Nowadays all the servers support it and it gives us some real benefits.
        $options = array(3=>'3', 2=>'2');
        $settings->add(new admin_setting_configselect('enrol_ldapcohorts/ldap_version', get_string('version_key', 'enrol_ldapcohorts'), get_string('version', 'enrol_ldapcohorts'), 3, $options));
        $settings->add(new admin_setting_configtext('enrol_ldapcohorts/ldapencoding', get_string('ldap_encoding_key', 'enrol_ldapcohorts'), get_string('ldap_encoding', 'enrol_ldapcohorts'), 'utf-8'));
        
        //--- bind settings
        $settings->add(new admin_setting_heading('enrol_ldapcohorts_bind_settings', get_string('bind_settings', 'enrol_ldapcohorts'), ''));
        $settings->add(new admin_setting_configtext_trim_lower('enrol_ldapcohorts/bind_dn', get_string('bind_dn_key', 'enrol_ldapcohorts'), get_string('bind_dn', 'enrol_ldapcohorts'), ''));
        $settings->add(new admin_setting_configpasswordunmask('enrol_ldapcohorts/bind_pw', get_string('bind_pw_key', 'enrol_ldapcohorts'), get_string('bind_pw', 'enrol_ldapcohorts'), ''));
        
        //--- cohort lookup settings
        $settings->add(new admin_setting_heading('enrol_ldapcohorts_cohort', get_string('cohort_lookup', 'enrol_ldapcohorts'), ''));
        $settings->add(new admin_setting_configtext('enrol_ldapcohorts/cohort_objectclass', get_string('objectclass_key', 'enrol_ldapcohorts'), get_string('objectclass', 'enrol_ldapcohorts'), '(objectClass=posixGroup)'));
        $settings->add(new admin_setting_configtext('enrol_ldapcohorts/cohort_contexts', get_string('cohort_contexts_key', 'enrol_ldapcohorts'), get_string('cohort_contexts', 'enrol_ldapcohorts'), ''));
        $settings->add(new admin_setting_configselect('enrol_ldapcohorts/cohort_search_sub', get_string('search_subcontexts_key', 'enrol_ldapcohorts'), get_string('cohort_search_sub', 'enrol_ldapcohorts'), key($yesno), $yesno));
        $cohortfields = array ('name', 'idnumber', 'description');
        foreach ($cohortfields as $field) {
            $settings->add(new admin_setting_ldapcohort_trim_lower('enrol_ldapcohorts/cohort_'.$field, get_string('cohort_'.$field.'_key', 'enrol_ldapcohorts'), get_string('cohort_'.$field, 'enrol_ldapcohorts'), ($field == 'description' ? 'description' : ($field == 'name' ? 'cn' : '')), true));
        }
        
        if (!during_initial_install()) {
            require_once($CFG->dirroot.'/course/lib.php');
            $options = get_category_options();
            $settings->add(new admin_setting_configselect('enrol_ldapcohorts/context', get_string('cohort_context_key', 'enrol_ldapcohorts'), get_string('cohort_context', 'enrol_ldapcohorts'), key($options), $options));
        }
        
        //--- user lookup settings
        $settings->add(new admin_setting_heading('enrol_ldap_cohort_user_settings', get_string('user_lookup', 'enrol_ldapcohorts'), ''));
        $usertypes = ldap_supported_usertypes();
        $settings->add(new admin_setting_configselect('enrol_ldapcohorts/user_type', get_string('user_type_key', 'enrol_ldapcohorts'), get_string('user_type', 'enrol_ldapcohorts'), end($usertypes), $usertypes));
        $opt_deref = array();
        $opt_deref[LDAP_DEREF_NEVER] = get_string('no');
        $opt_deref[LDAP_DEREF_ALWAYS] = get_string('yes');
        $settings->add(new admin_setting_configselect('enrol_ldapcohorts/user_deref', get_string('user_dereference_key', 'enrol_ldapcohorts'), get_string('user_dereference', 'enrol_ldapcohorts'), key($opt_deref), $opt_deref));
        $settings->add(new admin_setting_configtext('enrol_ldapcohorts/user_contexts', get_string('user_contexts_key', 'enrol_ldapcohorts'), get_string('user_contexts', 'enrol_ldapcohorts'), ''));
        $settings->add(new admin_setting_configselect('enrol_ldapcohorts/user_search_sub', get_string('search_subcontexts_key', 'enrol_ldapcohorts'), get_string('user_search_sub', 'enrol_ldapcohorts'), key($yesno), $yesno));
        $settings->add(new admin_setting_ldapcohort_trim_lower('enrol_ldapcohorts/user_member_attribute', get_string('user_member_attribute_key', 'enrol_ldapcohorts'), get_string('user_member_attribute', 'enrol_ldapcohorts'), 'memberUid', true));
        $settings->add(new admin_setting_ldapcohort_trim_lower('enrol_ldapcohorts/user_attribute', get_string('user_attribute_key', 'enrol_ldapcohorts'), get_string('user_attribute', 'enrol_ldapcohorts'), '', true));
        $settings->add(new admin_setting_ldapcohort_trim_lower('enrol_ldapcohorts/user_idnumber', get_string('user_idnumber_key', 'enrol_ldapcohorts'), get_string('user_idnumber', 'enrol_ldapcohorts'), 'uidnumber', true));
        $settings->add(new admin_setting_configtext('enrol_ldapcohorts/user_objectclass', get_string('objectclass_key', 'enrol_ldapcohorts'), get_string('user_objectclass', 'enrol_ldapcohorts'), ''));
        
    }
}