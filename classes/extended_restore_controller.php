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
namespace local_remote_backup_provider;

use backup;
use backup_controller_dbops;
use backup_helper_exception;
use coding_exception;
use context_system;
use dml_exception;
use DOMDocument;
use file_exception;
use local_remote_backup_provider\output\viewpage;
use moodle_exception;
use moodle_url;
use restore_controller;
use restore_controller_exception;
use restore_dbops;

defined('MOODLE_INTERNAL') || die();

// Apparently use restore_controller is not auto loaded, so use require_once.
require_once("{$CFG->dirroot}/backup/util/includes/restore_includes.php");

/**
 * Class restore_controller.
 *
 * @package local_remote_backup_provider
 */
class extended_restore_controller
{

    /**
     * @var remote_backup_provider
     */
    public $rbp;

    /**
     * @var object object containing the url the the remote backup file
     */
    public $remotecourse;

    /**
     * @param remote_backup_provider $rbp
     * @param int $remote
     * @throws dml_exception
     */
    public function __construct(remote_backup_provider $rbp, int $remote)
    {
        $this->rbp = $rbp;
        $params['id'] = $remote;
        // Generate the backup file on remote Moodle and store the link to the file in object.
        $this->remotecourse = $rbp->get_remote_data('local_remote_backup_provider_get_course_backup_by_id', $params);
        $this->fs = get_file_storage();
        $timestamp = time();

        $this->options = array(
            'timeout' => 30000,
            'connecttimeout' => 300,
            'skipcertverify' => true
        );

        $this->filerecord = array(
            'contextid' => $this->rbp->context->id,
            'component' => 'local_remote_backup_provider',
            'filearea' => 'backup',
            'itemid' => $timestamp,
            'filepath' => '/',
            'filename' => 'foo1',
            'timecreated' => $timestamp,
            'timemodified' => $timestamp
        );
    }

    /**
     * Modify the users.xml file in the course backup.
     *
     * @param array $userids
     * @param string $pathtoxml
     * @return false|int
     */
    public static function delete_user_from_xml(array $userids, string $pathtoxml)
    {

        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->load($pathtoxml);
        foreach ($userids as $userid) {
            $user = $dom->getElementById((string)$userid);
            $users = $dom->getElementsByTagName('user');

            foreach ($users as $user) {
                if ($user->getAttribute('id') == $userid) {
                    $parent = $user->parentNode;
                    $parent->removeChild($user);
                }
            }
        }
        $contents = $dom->saveXML();

        $result = file_put_contents($pathtoxml, $contents);
        return $result;
    }

    /**
     * Modify the users.xml file in the course backup.
     *
     * @param int $userid
     * @param string $pathtoxml
     * @param string $username
     * @param string $firstname
     * @param string $lastname
     * @param string $useremail
     * @return false|int
     */
    public static function update_user_from_xml(int $userid, string $pathtoxml, string $username = '', string $firstname = '',
                                                $lastname = '', $useremail = '')
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->load($pathtoxml);

        $users = $dom->getElementsByTagName('user');

        foreach ($users as $user) {
            if ($user->getAttribute('id') == $userid) {
                $user->getElementsByTagName('username')[0]->nodeValue = $username;
                $user->getElementsByTagName('firstname')[0]->nodeValue = $firstname;
                $user->getElementsByTagName('lastname')[0]->nodeValue = $lastname;
                $user->getElementsByTagName('email')[0]->nodeValue = $useremail;
            }
        }

        $contents = $dom->saveXML();
        return file_put_contents($pathtoxml, $contents);
    }

    /**
     * Get course backup from remote instance and then perform the restore via redirect to Moodle restore dialogue
     *
     * @throws file_exception
     * @throws moodle_exception
     */
    public function import_backup_file()
    {

        $storedfile = $this->fs->create_file_from_url($this->filerecord,
            $this->remotecourse->url . '?token=' . $this->rbp->token, $this->options, true);
        $restoreurl = new moodle_url('/backup/restore.php',
            array(
                'contextid' => $this->rbp->context->id,
                'pathnamehash' => $storedfile->get_pathnamehash(),
                'contenthash' => $storedfile->get_contenthash()
            )
        );
        redirect($restoreurl);
    }

    /**
     * Perform user precheck in order to decide how to match users from remote site with users from local site.
     *
     * @return array
     * @throws backup_helper_exception
     * @throws file_exception
     * @throws moodle_exception
     * @throws restore_controller_exception
     */
    public function perform_precheck()
    {
        global $USER;

        $tmpid = restore_controller::get_tempdir_name($this->rbp->id, $USER->id);
        $filepath = make_backup_temp_directory($tmpid);
        if (!check_dir_exists($filepath, true, true)) {
            throw new restore_controller_exception('cannot_create_backup_temp_dir');
        }

        $storedfile = $this->fs->create_file_from_url($this->filerecord, $this->remotecourse->url . '?token=' . $this->rbp->token,
            $this->options, true);

        $restoreurl = new moodle_url('/backup/restore.php',
            array(
                'contextid' => $this->rbp->context->id
            )
        );

        $fp = get_file_packer('application/vnd.moodle.backup');
        $fp->extract_to_pathname($storedfile, $filepath);
        // Access user.xml in backup?
        $rc = new restore_controller($tmpid, $this->rbp->id, backup::INTERACTIVE_NO,
            backup::MODE_IMPORT, $USER->id, backup::TARGET_CURRENT_ADDING);
        $rc->execute_precheck();
        $file = $rc->get_plan()->get_basepath() . '/users.xml';

        restore_dbops::load_users_to_tempids($rc->get_restoreid(), $file, $rc->get_progress());
        $users = $this->return_list_of_users_to_import($rc->get_restoreid());

        $list = $this->process_users($users, $rc->get_restoreid(), $restoreurl);
        $list['coursename'] = $this->get_course_name_from_backup($rc->get_plan()->get_basepath() . '/course/course.xml');

        return $list;
    }

    /**
     * Get users from temp table.
     *
     * @param string $restoreid
     * @return array
     * @throws dml_exception
     */
    private function return_list_of_users_to_import(string $restoreid)
    {
        global $DB;

        // To return any problem found.
        $users = array();

        // Prepare for reporting progress.
        $conditions = array('backupid' => $restoreid, 'itemname' => 'user');

        // Iterate over all the included users.
        $rs = $DB->get_recordset('backup_ids_temp', $conditions, '', 'itemid, info');
        foreach ($rs as $recuser) {
            $user = (object)backup_controller_dbops::decode_backup_temp_info($recuser->info);
            $users[] = $user;
        }
        return $users;
    }

    /**
     * Perform a user check and perform actions needed due to problems with non matching users.
     *
     * @param array $users
     * @param string $restoreid
     * @param moodle_url $restoreurl
     * @return array
     * @throws coding_exception
     * @throws dml_exception
     */
    private function process_users(array $users, string $restoreid, moodle_url $restoreurl)
    {
        global $DB, $USER, $CFG;
        $context = context_system::instance();
        $userid = $USER->id;

        // Check capability if logged in users is able to create users and see user details.
        $cancreateuser = false;
        if (has_capability('moodle/restore:createuser', $context, $userid) &&
            has_capability('moodle/restore:userinfo', $context, $userid) &&
            empty($CFG->disableusercreationonrestore)) { // Can create users.
            $cancreateuser = true;
        }

        $list = array();
        foreach ($users as $user) {
            // Look for troubles. First, no troubles, clean match.
            if ($recs = $DB->get_records('user', array('username' => $user->username, 'email' => $user->email))) {
                $matchuserstring = null;
            } else if ($recs = $DB->get_records('user', array('username' => $user->username))) {
                $matchuserstring = get_string('differentmail', 'local_remote_backup_provider');
            } else if ($recs = $DB->get_records('user', array('email' => $user->email))) {
                $matchuserstring = get_string('differentusername', 'local_remote_backup_provider');
            } else if ($recs = $DB->get_records('user', array('firstname' => $user->firstname, 'lastname' => $user->lastname))) {
                $matchuserstring = get_string('samefirstandlastname', 'local_remote_backup_provider');
            } else {

                // If you are allowed to create a new user, this will be green, else it will be red.
                if ($cancreateuser) {
                    $matchuserstring = get_string('createasnew', 'local_remote_backup_provider');
                } else {
                    $matchuserstring = get_string('notallowedtocreate', 'local_remote_backup_provider');
                }

            }

            $newuser = [
                'id' => $user->id,
                'username' => $user->username,
                'useremail' => $user->email,
                'firstname' => $user->firstname,
                'lastname' => $user->lastname,
                'matchuser' => $matchuserstring,
                'class' => 'table-success'
            ];

            // We can't import users if we don't have the right to.
            if ($matchuserstring == get_string('notallowedtocreate', 'local_remote_backup_provider')) {
                $newuser['class'] = 'table-danger';
            }

            // Add user to array.
            $list[] = $this->add_existing_users_to_newuser($newuser, $recs);
        }

        $list['users'] = $list;
        $list['restoreid'] = $restoreid;
        $list['restoreurl'] = $restoreurl;
        return $list;
    }

    private function add_existing_users_to_newuser($newuser, $recs)
    {
        $newuser['matchingusers'] = array();
        if ($recs && count($recs) > 0) {
            // Run through the result of our DB Search, we might have more than one match.
            foreach ($recs as $rec) {
                $existinguser = [
                    'id' => $rec->id,
                    'username' => $rec->username,
                    'useremail' => $rec->email,
                    'firstname' => $rec->firstname,
                    'lastname' => $rec->lastname,
                    'matchuser' => get_string('existinguser', 'local_remote_backup_provider')
                ];

                if ($newuser['matchuser'] != null) {
                    $newuser['class'] = 'table-danger';
                }
                array_push($newuser['matchingusers'], $existinguser);
            }
        }
        return $newuser;
    }

    /**
     * function to return coursname, found in the course.xml file which ist part of the backup bundle
     * @param string $pathtoxml path to course.xml file in our extracted backup found in the temp directory
     * @return string cousename
     */
    private function get_course_name_from_backup(string $pathtoxml): string
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->load($pathtoxml);
        $coursename = $dom->getElementsByTagName('fullname')->item(0)->nodeValue;
        return $coursename;
    }

    /**
     * Hand over data to renderer.
     *
     * @param array $list
     * @return string
     */
    public function display_userlist(array $list)
    {
        global $PAGE;
        $output = $PAGE->get_renderer('local_remote_backup_provider');
        $out = '';
        // Create the list of open games we can pass on to the renderer.
        $viewpage = new viewpage($list);
        $out .= $output->render_viewpage($viewpage);
        return $out;
    }
}