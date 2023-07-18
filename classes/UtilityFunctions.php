<?php declare(strict_types = 1);

namespace mod_edusharing;

use cached_cm_info;
use coding_exception;
use context_course;
use context_system;
use dml_exception;
use Exception;
use mod_edusharing\apiService\EduSharingService;
use stdClass;

class UtilityFunctions
{
    /**
     * Function getObjectIdFromUrl
     *
     * Get the object-id from object-url.
     * E.g. "abc-123-xyz-456789" for "ccrep://homeRepository/abc-123-xyz-456789"
     *
     * @param string $url
     * @return string
     */
    public static function getObjectIdFromUrl(string $url): string {
        $objectId = parse_url($url, PHP_URL_PATH);
        if ($objectId === false ) {
            try {
                trigger_error(get_string('error_get_object_id_from_url', 'edusharing'), E_USER_WARNING);
            } catch (Exception $exception) {
                trigger_error('error_get_object_id_from_url', E_USER_WARNING);
            }
            return '';
        }

        return str_replace('/', '', $objectId);
    }

    /**
     * Function getRepositoryIdFromUrl
     *
     * Get the repository-id from object-url.
     * E.g. "homeRepository" for "ccrep://homeRepository/abc-123-xyz-456789"
     *
     * @param string $url
     * @return string
     * @throws Exception
     */
    public static function getRepositoryIdFromUrl(string $url): string {
        $repoId = parse_url($url, PHP_URL_HOST);
        if ($repoId === false) {
            throw new Exception(get_string('error_get_repository_id_from_url', 'edusharing'));
        }

        return $repoId;
    }

    /**
     * Functions getRedirectUrl
     *
     * @throws dml_exception|coding_exception
     */
    public static function getRedirectUrl(stdClass $eduSharing, string $displaymode = EDUSHARING_DISPLAY_MODE_DISPLAY): string {
        global $USER;
        $url = get_config('edusharing', 'application_cc_gui_url') . '/renderingproxy';
        $url .= '?app_id='.urlencode(get_config('edusharing', 'application_appid'));
        $url .= '&session='.urlencode(session_id());
        try {
            $repoId = static::getRepositoryIdFromUrl($eduSharing->object_url);
        } catch (Exception $exception) {
            error_log($exception->getMessage());
            return '';
        }
        $url .= '&rep_id='.urlencode($repoId);
        $url .= '&obj_id='.urlencode(static::getObjectIdFromUrl($eduSharing->object_url));
        $url .= '&resource_id='.urlencode($eduSharing->id);
        $url .= '&course_id='.urlencode($eduSharing->course);
        $context = context_course::instance($eduSharing->course);
        $roles = get_user_roles($context, $USER->id);
        foreach ($roles as $role) {
            $url .= '&role=' . urlencode($role -> shortname);
        }
        $url .= '&display='.urlencode($displaymode);
        $url .= '&version=' . urlencode($eduSharing->object_version);
        $url .= '&locale=' . urlencode(current_language()); //repository
        $url .= '&language=' . urlencode(current_language()); //rendering service
        $url .= '&u='. rawurlencode(base64_encode(static::encryptWithRepoKey(static::getAuthKey())));

        return $url;
    }

    /**
     * Function getAuthKey
     *
     * @throws dml_exception
     */
    public static function getAuthKey(): string {
        global $USER, $SESSION;

        // Set by external sso script.
        if (!empty($SESSION['edusharing_sso'])) {
            return $SESSION['edusharing_sso'][get_config('edusharing', 'EDU_AUTH_PARAM_NAME_USERID')];
        }
        $guestOption = get_config('edusharing', 'edu_guest_option');
        if (!empty($guestOption)) {
            $guestId = get_config('edusharing', 'edu_guest_guest_id');

            return !empty($guestId) ? $guestId : 'esguest';
        }
        $eduAuthKey = get_config('edusharing', 'EDU_AUTH_KEY');
        if($eduAuthKey == 'id')
            return $USER->id;
        if($eduAuthKey == 'idnumber')
            return $USER->idnumber;
        if($eduAuthKey == 'email')
            return $USER->email;
        if(isset($USER->profile[$eduAuthKey]))
            return $USER->profile[$eduAuthKey];
        return $USER->username;
    }

    /**
     * Function encryptWithRepoKey
     *
     * @throws coding_exception
     * @throws dml_exception
     */
    public static function encryptWithRepoKey(string $data): string {
        $encrypted = '';
        $key       = openssl_get_publickey(get_config('edusharing', 'repository_public_key'));
        if(! openssl_public_encrypt($data ,$encrypted, $key)) {
            trigger_error(get_string('error_encrypt_with_repo_public', 'edusharing'), E_USER_WARNING);
            return '';
        }
        return $encrypted;
    }

    /**
     * Function setModuleIdInDb
     *
     * @param string $text
     * @param array $data
     * @param string $id_type
     * @return void
     */
    public static function setModuleIdInDb(string $text, array $data, string $id_type): void {
        global $DB;
        preg_match_all('#<img(.*)class="(.*)edusharing_atto(.*)"(.*)>#Umsi', $text, $matchesImgAtto, PREG_PATTERN_ORDER);
        preg_match_all('#<a(.*)class="(.*)edusharing_atto(.*)">(.*)</a>#Umsi', $text, $matchesAAtto, PREG_PATTERN_ORDER);
        $matches_atto = array_merge($matchesImgAtto[0], $matchesAAtto[0]);

        if ( !empty($matches_atto)) {
            foreach ($matches_atto as $match) {
                $resourceId = '';
                if (($pos = strpos($match, "resourceId=")) !== FALSE) {
                    $resourceId = substr($match, $pos + 11);
                    $resourceId = substr($resourceId, 0, strpos($resourceId, "&"));
                }
                try {
                    $DB->set_field('edusharing', $id_type, $data['objectid'], array('id' => $resourceId));
                } catch (Exception $exception) {
                    error_log('Could not set module_id: ' . $exception->getMessage());
                }
            }
        }
    }

    /**
     * Function addInstance
     *
     * @param stdClass $eduSharing
     * @return bool|int
     */
    public static function addInstance(stdClass $eduSharing): bool|int
    {
        global $DB;

        $eduSharing->timecreated  = time();
        $eduSharing->timemodified = time();

        // You may have to add extra stuff in here.
        static::postProcessEdusharingObject($eduSharing);
        $updateVersion = false;

        //use simple version handling for atto plugin or legacy code
        if (isset($eduSharing -> editor_atto)) {
            //avoid database error
            $eduSharing->introformat = 0;
        } else {
            if (isset($eduSharing->object_version)) {
                if ((int)$eduSharing->object_version === 1) {
                    $updateVersion              = true;
                    $eduSharing->object_version = '';
                } else {
                    $eduSharing->object_version = 0;
                }
            } else {
                if (isset($eduSharing->window_versionshow) && $eduSharing->window_versionshow == 'current') {
                    $eduSharing->object_version = $eduSharing->window_version;
                } else {
                    $eduSharing->object_version = 0;
                }
            }
        }
        try {
            $id = $DB->insert_record(EDUSHARING_TABLE, $eduSharing);
        } catch (Exception $exception) {
            error_log($exception->getMessage());
            return false;
        }
        $eduService              = new EduSharingService();
        $usageData               = new stdClass();
        $usageData->containerId  = $eduSharing->course;
        $usageData->resourceId   = $id;
        $usageData->nodeId       = UtilityFunctions::getObjectIdFromUrl($eduSharing->object_url);
        $usageData->nodeVersion  = $eduSharing->object_version;
        try {
            $usage                = $eduService->createUsage($usageData);
            $eduSharing->id       = $id;
            $eduSharing->usage_id = $usage->usageId;
            if ($updateVersion) {
                $eduSharing->object_version = $usage->nodeVersion;
            }
            $DB->update_record(EDUSHARING_TABLE, $eduSharing);
            return $id;
        } catch (Exception $exception) {
            error_log($exception->getMessage());
            try {
                $DB->delete_records(EDUSHARING_TABLE, ['id'  => $id]);
            } catch (Exception $deleteException) {
                error_log($deleteException->getMessage());
            }
            return false;
        }
    }

    public static function updateInstance(stdClass $edusharing): bool {
        global $DB;
        // FIX: when editing a moodle-course-module the $edusharing->id will be named $edusharing->instance
        if (!empty($edusharing->instance)) {
            $edusharing->id = $edusharing->instance;
        }
        static::postProcessEdusharingObject($edusharing);
        $usageData               = new stdClass ();
        $usageData->containerId  = $edusharing->course;
        $usageData->resourceId   = $edusharing->id;
        $usageData->nodeId       = UtilityFunctions::getObjectIdFromUrl($edusharing->object_url);
        $usageData->nodeVersion  = $edusharing->object_version;
        $service                 = new EduSharingService();
        try {
            $memento           = $DB->get_record(EDUSHARING_TABLE,  ['id'  => $edusharing->id], '*', MUST_EXIST);
            $usageData->ticket = $service->getTicket();
        } catch (Exception $exception) {
            error_log($exception->getMessage());
            return false;
        }
        try {
            $usage                = $service->createUsage($usageData);
            $edusharing->usage_id = $usage->usageId;
            $DB->update_record(EDUSHARING_TABLE, $edusharing);
        } catch (Exception $exception) {
            error_log($exception->getMessage());
            try {
                $DB->update_record(EDUSHARING_TABLE, $memento);
            } catch (Exception $updateException) {
                error_log($updateException->getMessage());
            }
            return false;
        }
        return true;
    }

    /**
     * Function postProcessEdusharingObject
     *
     * @param stdClass $edusharing
     * @return void
     */
    public static function postProcessEdusharingObject(stdClass $edusharing): void {
        global $COURSE;
        if (empty($edusharing->timecreated)) {
            $edusharing->timecreated = time();
        }
        $edusharing->timeupdated = time();
        if (!empty($edusharing->force_download)) {
            $edusharing->force_download = 1;
            $edusharing->popup_window = 0;
        } else if (!empty($edusharing->popup_window)) {
            $edusharing->force_download = 0;
            $edusharing->options = '';
        } else {
            if (empty($edusharing->blockdisplay)) {
                $edusharing->options = '';
            }
            $edusharing->popup_window = '';
        }
        $edusharing->tracking = empty($edusharing->tracking) ? 0 : $edusharing->tracking;
        if (!$edusharing->course) {
            $edusharing->course = $COURSE->id;
        }
    }

    public static function updateSettingsImages(string $settingName): void {
        global $CFG;
        // The setting name that was updated comes as a string like 's_theme_photo_loginbackgroundimage'.
        // We split it on '_' characters.
        $parts       = explode('_', $settingName);
        // And get the last one to get the setting name..
        $settingName = end($parts);
        $component   = 'edusharing';
        // Admin settings are stored in system context.
        try {
            $sysContext  = context_system::instance();
            $filename = get_config($component, $settingName);
        } catch (Exception $exception) {
            error_log($exception->getMessage());
            return;
        }
        // This is the value of the admin setting which is the filename of the uploaded file.
        // We extract the file extension because we want to preserve it.
        $extension = substr($filename, strrpos($filename, '.') + 1);
        // This is the path in the moodle internal file system.
        $fullPath  = "/{$sysContext->id}/{$component}/{$settingName}/0{$filename}";
        // Get an instance of the moodle file storage.
        $fs = get_file_storage();
        // This is an efficient way to get a file if we know the exact path.
        if ($file = $fs->get_file_by_hash(sha1($fullPath))) {
            // We got the stored file - copy it to data root.
            // This location matches the searched for location in theme_config::resolve_image_location.
            $pathname = $CFG->dataroot . '/pix_plugins/mod/edusharing/icon.' . $extension;
            // This pattern matches any previous files with maybe different file extensions.
            $pathPattern = $CFG->dataroot . '/pix_plugins/mod/edusharing/icon.*';
            // Make sure this dir exists.
            @mkdir($CFG->dataroot . '/pix_plugins/mod/edusharing/', $CFG->directorypermissions, true);
            // Delete any existing files for this setting.
            foreach (glob($pathPattern) as $filename) {
                @unlink($filename);
            }
            // Copy the current file to this location.
            $file->copy_content_to($pathname);
        } else {
            $pathPattern = $CFG->dataroot . '/pix_plugins/mod/edusharing/icon.*';
            // Make sure this dir exists.
            @mkdir($CFG->dataroot . '/pix_plugins/mod/edusharing/', $CFG->directorypermissions, true);
            // Delete any existing files for this setting.
            foreach (glob($pathPattern) as $filename) {
                @unlink($filename);
            }
        }
        // Reset theme caches.
        theme_reset_all_caches();
    }

    public static function getCourseModuleInfo(stdClass $courseModule): cached_cm_info|bool {
        global $DB;
        try {
            $edusharing = $DB->get_record('edusharing', ['id' => $courseModule->instance], 'id, name, intro, introformat', MUST_EXIST);
        } catch (Exception $exception) {
            error_log($exception->getMessage());
            return false;
        }
        $info       = new cached_cm_info();
        if ($courseModule->showdescription) {
            // Convert intro to html. Do not filter cached version, filters run at display time.
            $info->content = format_module_intro('edusharing', $edusharing, $courseModule->id, false);
        }
        try {
            $resource = $DB->get_record(EDUSHARING_TABLE, ['id'  => $courseModule->instance], '*', MUST_EXIST);
            if (!empty($resource->popup_window)) {
                $info->onclick = 'this.target=\'_blank\';';
            }
        } catch (Exception $exception) {
            error_log($exception->getMessage());
        }
        return $info;
    }
}
