<?php
/**
 * Plugin Name: pannellumpress
 * Plugin URI: http://URI_Of_Page_Describing_Plugin_and_Updates
 * Description: Integration of the pannellum panorama viewer into wordpress
 * Version: 0.1
 * Author: Johannes Wienke
 * Author URI: http://www.semipol.de
 * License: LGPLv3
 */

function pannellumpress_upload_folder() {
    return wp_upload_dir()['basedir'] . '/panellumpress';
}

add_action('init', 'pannellumpress_init');
function pannellumpress_init() {
    // ensure that the upload folder exists
    if (!file_exists(pannellumpress_upload_folder())) {
        mkdir(pannellumpress_upload_folder(), 0777, true);
    }
}

add_shortcode('pannellum', 'pannellumpress_shortcode');
function pannellumpress_shortcode($attributes, $content='', $code='') {
    // Get attributes as parameters
    extract(shortcode_atts(array(
            'width' => 600,
            'height' => 450,
            'title' => 'Pannellum Panorama',
        ), $attributes));

    // Sanitize
    $width = intval($width);
    $height = intval($height);

    $pannellum_url = plugins_url('pannellum/src/pannellum.htm', __FILE__);

    return '<iframe title="' . esc_attr($title) . '" width="' . $width . '" height="' . $height . '" webkitAllowFullScreen mozallowfullscreen allowFullScreen style="border-style:none;" src="' . $pannellum_url . '?config=../examples/config.json"></iframe>';

}

define('PANNELLUMPRESS_MANAGE_CAPACITY', 'upload_files');

add_action('admin_menu', 'pannellumpress_admin_menu');
function pannellumpress_admin_menu() {
    $manage_hook_suffix = add_media_page('Pannellumpress Panoramas', 'Panoramas', PANNELLUMPRESS_MANAGE_CAPACITY, 'pannellumpress_manage', 'pannellumpress_manage_page');
}

// form field names
define('PANNELLUMPRESS_MANAGE_HIDDEN_FIELD', 'pannellumpress_manage_existing_hidden');
define('PANNELLUMPRESS_UPLOAD_HIDDEN_FIELD', 'pannellumpress_upload_hidden');
define('PANNELLUMPRESS_UPLOAD_NAME_FIELD', 'name');
define('PANNELLUMPRESS_UPLOAD_CONFIG_FIELD', 'config');
define('PANNELLUMPRESS_UPLOAD_DATA_FIELD', 'data');
define('PANNELLUMPRESS_UPLOAD_PREVIEW_FIELD', 'preview');

function pannellumpress_max_upload_size() {
    $max_upload = (int)(ini_get('upload_max_filesize'));
    $max_post = (int)(ini_get('post_max_size'));
    $memory_limit = (int)(ini_get('memory_limit'));
    return min($max_upload, $max_post, $memory_limit) * 1024 * 1024;
}

class UploadException extends Exception {
    public function __construct($message, $code = 0) {
        parent::__construct($message, $code);
    }
}

function pannellumpress_delete_dir($dirPath) {
    if (!is_dir($dirPath)) {
        throw new InvalidArgumentException("$dirPath must be a directory");
    }
    if (substr($dirPath, strlen($dirPath) - 1, 1) != '/') {
        $dirPath .= '/';
    }
    $files = glob($dirPath . '*', GLOB_MARK);
    foreach ($files as $file) {
        if (is_dir($file)) {
            pannellumpress_delete_dir($file);
        } else {
            unlink($file);
        }
    }
    rmdir($dirPath);
}

function pannellumpress_process_upload() {

    if (empty($_POST[PANNELLUMPRESS_UPLOAD_HIDDEN_FIELD])) {
        return;
    }

    // validate name
    if (empty($_POST[PANNELLUMPRESS_UPLOAD_NAME_FIELD]) || !preg_match("/^[a-zA-Z0-9]{5,30}$/", $_POST[PANNELLUMPRESS_UPLOAD_NAME_FIELD])) {
        throw new UploadException("The panorama name must consist only of numbers and characters and have a length of 5 to 30 characters.");
    }
    $name = $_POST[PANNELLUMPRESS_UPLOAD_NAME_FIELD];
    if (file_exists(pannellumpress_upload_folder() . '/' . $name)) {
        throw new UploadException('There is already a panorama with name "' . $name . '".');
    }

    // validate config
    if (!is_uploaded_file($_FILES[PANNELLUMPRESS_UPLOAD_CONFIG_FIELD]['tmp_name'])) {
        throw new UploadException("A JSON configuration file must be provided.");
    }
    $config_contents = json_decode(file_get_contents($_FILES[PANNELLUMPRESS_UPLOAD_CONFIG_FIELD]['tmp_name']));
    if ($config_contents == NULL) {
        throw new UploadException("The provided config file is not a valid JSON file.");
    }

    // validate data
    if (!is_uploaded_file($_FILES[PANNELLUMPRESS_UPLOAD_DATA_FIELD]['tmp_name'])) {
        throw new UploadException("Either an image or a ZIP file for the panorama data needs to be uploaded.");
    }

    // validate preview
    //if (!is_uploaded_file($_FILES[PANNELLUMPRESS_UPLOAD_PREVIEW_FIELD]['tmp_name'])) {
    //    throw new UploadException("A preview image needs to be uploaded.");
    //}

    // perform the real upload
    $folder = pannellumpress_upload_folder() . "/" . $name;
    if (!mkdir($folder)) {
        throw new UploadException("Cannot create panorama folder '" . $folder . "'.");
    }
    try {

        // move the config file to its place
        if (!move_uploaded_file($_FILES[PANNELLUMPRESS_UPLOAD_CONFIG_FIELD]['tmp_name'], $folder . "/config.json")) {
            throw new UploadException("Unable to move config file to the target directory.");
        }

        // move the data to the target directory
        // first, find out whether we are dealing with a zip file
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $data_type = finfo_file($finfo, $_FILES[PANNELLUMPRESS_UPLOAD_DATA_FIELD]['tmp_name']);
        finfo_close($finfo);
        if ($data_type == 'application/zip') {
            $zip = new ZipArchive;
            if ($zip->open($_FILES[PANNELLUMPRESS_UPLOAD_DATA_FIELD]['tmp_name']) === TRUE) {
                $zip->extractTo($folder);
                $zip->close();
            } else {
                throw new UploadException('Extracting the provided ZIP file failed.');
            }
        } else {
            if (!move_uploaded_file($_FILES[PANNELLUMPRESS_UPLOAD_DATA_FIELD]['tmp_name'], $folder . '/' . $_FILES[PANNELLUMPRESS_UPLOAD_DATA_FIELD]['name'])) {
                throw new UploadException('Unable to move the provided panorama image to the destination folder.');
            }
        }

    } catch (UploadException $e) {
        // in case something goes wrong, delete the already created files again
        pannellumpress_delete_dir($folder);
        throw $e;
    }

}

function pannellumpress_manage_page() {

    // check permissions
    if (!current_user_can(PANNELLUMPRESS_MANAGE_CAPACITY)) {
        wp_die(__('You do not have sufficient permissions to access this page.'));
    }
?>
    <h2>Pannellumpress Panorama Management</h2>

<?php
    try {
        pannellumpress_process_upload();
    } catch (UploadException $e) {
        echo "Upload error: " . $e->getMessage();
    }
?>

    <h3>Upload a new panorama</h3>
    <form id="upload" action="" method="post" enctype="multipart/form-data">
        <input type="hidden" name="<?php echo PANNELLUMPRESS_UPLOAD_HIDDEN_FIELD; ?>" value="Y">
        <input type="hidden" name="MAX_FILE_SIZE" value="<?php echo pannellumpress_max_upload_size(); ?>" />
        <p>
            <label for="<?php echo PANNELLUMPRESS_UPLOAD_NAME_FIELD; ?>">Name:</label>
            <input name="<?php echo PANNELLUMPRESS_UPLOAD_NAME_FIELD; ?>" type="text" size="30" maxlength="30">
        </p>
        <p>
            <label for="<?php echo PANNELLUMPRESS_UPLOAD_CONFIG_FIELD; ?>">JSON config file:</label>
            <input type="file" name="<?php echo PANNELLUMPRESS_UPLOAD_CONFIG_FIELD; ?>" id="" />
        </p>
        <p>
            <label for="<?php echo PANNELLUMPRESS_UPLOAD_DATA_FIELD; ?>">Panorama image or ZIP file for multiple images:</label>
            <input type="file" name="<?php echo PANNELLUMPRESS_UPLOAD_DATA_FIELD; ?>" id="" />
        </p>
        <!--<p>
            <label for="<?php echo PANNELLUMPRESS_UPLOAD_PREVIEW_FIELD; ?>">Preview image:</label>
            <input type="file" name="<?php echo PANNELLUMPRESS_UPLOAD_PREVIEW_FIELD; ?>" id="" />
        </p>-->
        <p>
            <input type="submit" name="" id="doaction" class="button action" value="Upload" />
        </p>
    </form>

    <h3>Existing Panoramas</h3>
    <form id="manage-existing" action="" method="post">
        <input type="hidden" name="<?php echo PANNELLUMPRESS_MANAGE_HIDDEN_FIELD; ?>" value="Y">

        <div class="alignleft actions bulkactions">
            <select name='action'>
                <option value='-1' selected='selected'>Bulk Actions</option>
                <option value='delete'>Delete Permanently</option>
            </select>
            <input type="submit" name="" id="doaction" class="button action" value="Apply"  />
        </div>

        <table class="wp-list-table widefat fixed media">
            <thead>
                <tr>
                    <th scope='col' id='cb' class='manage-column column-cb check-column'  style=""><label class="screen-reader-text" for="cb-select-all-1">Select All</label><input id="cb-select-all-1" type="checkbox" /></th>
                    <!--<th scope='col' id='icon' class='manage-column column-icon'  style=""></th>-->
                    <th scope='col'>Name</th>
                </tr>
            </thead>
            <tbody id="the-list">
<?php

    // add an entry for each folder
    if ($handle = opendir(pannellumpress_upload_folder())) {
        while (($entry = readdir($handle)) !== false) {
            if ($entry != "." && $entry != ".." && is_dir(pannellumpress_upload_folder() . '/' . $entry)) {
?>
                <tr>
                    <th scope="row" class="check-column">
                        <label class="screen-reader-text" for="cb-select-<?php echo esc_attr($entry); ?>">Select <?php echo esc_html($entry); ?></label>
                        <input type="checkbox" name="deletes" id="cb-select-<?php echo esc_attr($entry); ?>" value="<?php echo esc_attr($entry); ?>" />
                    </th>
                    <td class='name'><?php echo esc_html($entry); ?></td>
                </tr>
<?php
            }
        }
        closedir($handle);
    }

?>

            </tbody>
        </table>

    </form>
<?php

}

?>
