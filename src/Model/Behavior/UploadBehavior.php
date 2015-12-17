<?php
namespace Xety\Cake3Upload\Model\Behavior;

use Cake\Event\Event;
use Cake\Filesystem\File;
use Cake\Filesystem\Folder;
use Cake\ORM\Behavior;
use Cake\ORM\Entity;

class UploadBehavior extends Behavior
{

    /**
     * Default config.
     *
     * @var array
     */
    protected $_defaultConfig = [
        'root' => WWW_ROOT,
        'suffix' => '_file',
        'fields' => []
    ];

    /**
     * Overwrite all file on upload.
     *
     * @var bool
     */
    protected $_overwrite = true;

    /**
     * The prefix of the file.
     *
     * @var bool|string
     */
    protected $_prefix = false;

    /**
     * The default file of the field.
     *
     * @var bool|string
     */
    protected $_defaultFile = false;

    /**
     * Check if there is some files to upload and modify the entity before
     * it is saved.
     *
     * At the end, for each files to upload, unset their "virtual" property.
     *
     * @param Event  $event  The beforeSave event that was fired.
     * @param Entity $entity The entity that is going to be saved.
     *
     * @throws \LogicException When the path configuration is not set.
     * @throws \ErrorException When the function to get the upload path failed.
     *
     * @return void
     */
    public function beforeSave(Event $event, Entity $entity)
    {
        $config = $this->_config;

        foreach ($config['fields'] as $field => $fieldOption) {
            $data = $entity->toArray();
            $virtualField = $field . $config['suffix'];

            if (!isset($data[$virtualField]) || !is_array($data[$virtualField])) {
                continue;
            }

            $file = $entity->get($virtualField);

            $error = $this->_triggerErrors($file);

            if ($error === false) {
                continue;
            } elseif (is_string($error)) {
                throw new \ErrorException($error);
            }

            if (!isset($fieldOption['path'])) {
                throw new \LogicException(__('The path for the {0} field is required.', $field));
            }

            if (isset($fieldOption['prefix']) && (is_bool($fieldOption['prefix']) || is_string($fieldOption['prefix']))) {
                $this->_prefix = $fieldOption['prefix'];
            }

            $extension = (new File($file['name'], false))->ext();
            
            $fieldIdentifiers = isset($fieldOption['fieldIdentifiers']) ? (bool)$fieldOption['fieldIdentifiers'] : true;
            $identifiers = $this->_buildIdentifiersArray($data, $fieldIdentifiers);
            if (!$identifiers) {
                throw new \ErrorException(__('Error building identifiers array'));
            }
            
            $uploadPath = $this->_getUploadPath($entity, $identifiers, $fieldOption['path'], $extension);
            if (!$uploadPath) {
                throw new \ErrorException(__('Error to get the uploadPath.'));
            }

            $folder = new Folder($this->_config['root']);
            $folder->create($this->_config['root'] . dirname($uploadPath));

            if ($this->_moveFile($entity, $file['tmp_name'], $uploadPath, $field, $fieldOption)) {
                if (!$this->_prefix) {
                    $this->_prefix = '';
                }

                $entity->set($field, $this->_prefix . $uploadPath);
            }

            $entity->unsetProperty($virtualField);
        }
    }

    /**
     * Trigger upload errors.
     *
     * @param  array $file The file to check.
     *
     * @return string|int|void
     */
    protected function _triggerErrors($file)
    {
        if (!empty($file['error'])) {
            switch ((int)$file['error']) {
                case UPLOAD_ERR_INI_SIZE:
                    $message = __('The uploaded file exceeds the upload_max_filesize directive in php.ini : {0}', ini_get('upload_max_filesize'));
                    break;

                case UPLOAD_ERR_FORM_SIZE:
                    $message = __('The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form.');
                    break;

                case UPLOAD_ERR_NO_FILE:
                    $message = false;
                    break;

                case UPLOAD_ERR_PARTIAL:
                    $message = __('The uploaded file was only partially uploaded.');
                    break;

                case UPLOAD_ERR_NO_TMP_DIR:
                    $message = __('Missing a temporary folder.');
                    break;

                case UPLOAD_ERR_CANT_WRITE:
                    $message = __('Failed to write file to disk.');
                    break;

                case UPLOAD_ERR_EXTENSION:
                    $message = __('A PHP extension stopped the file upload.');
                    break;

                default:
                    $message = __('Unknown upload error.');
            }

            return $message;
        }
    }

    /**
     * Move the temporary source file to the destination file.
     *
     * @param \Cake\ORM\Entity $entity      The entity that is going to be saved.
     * @param bool|string      $source      The temporary source file to copy.
     * @param bool|string      $destination The destination file to copy.
     * @param bool|string      $field       The current field to process.
     * @param array            $options     The configuration options defined by the user.
     *
     * @return bool
     */
    protected function _moveFile(Entity $entity, $source = false, $destination = false, $field = false, array $options = [])
    {
        if ($source === false || $destination === false || $field === false) {
            return false;
        }

        if (isset($options['overwrite']) && is_bool($options['overwrite'])) {
            $this->_overwrite = $options['overwrite'];
        }

        if ($this->_overwrite) {
            $this->_deleteOldUpload($entity, $field, $destination, $options);
        }

        $file = new File($source, false, 0755);

        if ($file->copy($this->_config['root'] . $destination, $this->_overwrite)) {
            return true;
        }

        return false;
    }

    /**
     * Delete the old upload file before to save the new file.
     *
     * We can not just rely on the copy file with the overwrite, because if you use
     * an identifier like :md5 (Who use a different name for each file), the copy
     * function will not delete the old file.
     *
     * @param \Cake\ORM\Entity $entity  The entity that is going to be saved.
     * @param bool|string      $field   The current field to process.
     * @param bool|string      $newFile The new file path.
     * @param array            $options The configuration options defined by the user.
     *
     * @return bool
     */
    protected function _deleteOldUpload(Entity $entity, $field = false, $newFile = false, array $options = [])
    {
        if ($field === false || $newFile === false) {
            return true;
        }

        $fileInfo = pathinfo($entity->$field);
        $newFileInfo = pathinfo($newFile);

        if (isset($options['defaultFile']) && (is_bool($options['defaultFile']) || is_string($options['defaultFile']))) {
            $this->_defaultFile = $options['defaultFile'];
        }

        if ($fileInfo['basename'] == $newFileInfo['basename'] ||
            $fileInfo['basename'] == pathinfo($this->_defaultFile)['basename']) {
            return true;
        }

        if ($this->_prefix) {
            $entity->$field = str_replace($this->_prefix, "", $entity->$field);
        }

        $file = new File($this->_config['root'] . $entity->$field, false);

        if ($file->exists()) {
            $file->delete();
            return true;
        }

        return false;
    }

    /**
     * Builds identifiers array using both default values and fields from entity.
     *
     * Identifiers :
     *      :md5   : A random and unique identifier with 32 characters.
     *      :y     : Based on the current year.
     *      :m     : Based on the current month.
     *      :d     : Based on the current day.
     *      :field : Any array field within the entity. see notes for _addIdentifiers
     *                 for more information.
     *
     * @param array $entityArray      Entity information in array format.
     * @param bool  $fieldIdentifiers Set as false to disable field identifiers.
     *
     * @return bool|string
     */
    protected function _buildIdentifiersArray(array $entityArray, $fieldIdentifiers = true)
    {
        $identifiers = [
            ':id' => isset($entityArray['id']) ? $entityArray['id'] : 'id',
            ':md5' => md5(rand() . uniqid() . time()),
            ':y' => date('Y'),
            ':m' => date('m'),
            ':d' => date('d')
        ];
        
        if ($fieldIdentifiers === true) {
            $fieldIdentifiersArray = [];
            foreach ($entityArray as $key => $value) {
                $fieldIdentifiersArray = $this->_addIdentifiers($key, $value, '', $fieldIdentifiersArray);
            }
            $identifiers = array_unique(array_merge($identifiers, $fieldIdentifiersArray));
        }
        
        return $identifiers;
    }

    /**
     * Recurses an entity array and builds key-value pairs, which are then added to
     * $identifiers array.
     *
     * Sub-array (associated values) values are notated as :parent.child so for
     * instance if articles has one creator, and you want to get that creator's name,
     * you would use :creator.name to access this value.
     *
     * If the user references an empty value, the $key is used instead.
     *
     * @param string $key               The key for the value being analyzed.
     * @param string $value             The value for the value being analyzed.
     * @param string $prefix            For deeply associated values, the prefix used to
     *                                  denote parent in :parent.child relationship.
     * @param array  $fieldIdentifiers  Identifiers for fields already processed.
     *
     * @return string
     */
    protected function _addIdentifiers($key, $value, $prefix, array $fieldIdentifiers)
    {
        if (is_array($value)) {
            strcmp($prefix, '') ? $prefix = $prefix . '.' . $key : $prefix = $key;
            $arrayKeys = array_keys($value);
            if (array_keys($arrayKeys) !== $arrayKeys) {
                foreach ($value as $subKey => $subValue) {
                    $fieldIdentifiers = $this->_addIdentifiers($subKey, $subValue, $prefix, $fieldIdentifiers);
                }
            }
        } else {
            strcmp($prefix, '') ? $field = ':' . $prefix . '.' . $key : $field = ':' . $key;
            empty($value) ? $fieldIdentifiers[$field] = $key : $fieldIdentifiers[$field] = $this->_sanitizeField($value);
        }
        
        return $fieldIdentifiers;
    }

    /**
     * When using database values to create paths, we have to ensure these values comply
     * with naming conventions for files in folders in Windows and Linux. This function
     * replaces reserved or illegal charachters for both operating systems with a "-".
     *
     * File and folder naming conventions:
     * Windows: https://msdn.microsoft.com/en-us/library/windows/desktop/aa365247.aspx
     * Linux: http://www.cyberciti.biz/faq/linuxunix-rules-for-naming-file-and-directory-names/
     *
     * This function also limits the legnth of field values to 40 charachters, to avoid
     * path legnths which are too long. This limit is 255 charachters in linux and 260
     * charachters in Windows.
     *
     * @param string $value identifier value to be sanitized.
     *
     * @return string
     */
    protected function _sanitizeField($value)
    {
        $value = mb_ereg_replace("([^\w\s\d\-_~,;\[\]\(\)])", '-', $value);
        $value = mb_ereg_replace("([\.]{2,})", '', $value);
        $value = substr($value, 0, 40);
        return $value;
    }

    /**
     * Get the path formatted without its identifiers to upload the file.
     *
     * i.e : upload/:id/:md5 -> upload/2/5e3e0d0f163196cb9526d97be1b2ce26.jpg
     *
     * @param \Cake\ORM\Entity $entity      The entity that is going to be saved.
     * @param array            $identifiers Identifiers used in string replacement.
     * @param bool|string      $path        The path to upload the file with its identifiers.
     * @param bool|string      $extension   The extension of the file.
     *
     * @return bool|string
     */
    protected function _getUploadPath(Entity $entity, array $identifiers, $path = false, $extension = false)
    {
        if ($extension === false || $path === false) {
            return false;
        }

        $path = trim($path, DS);

        return strtr($path, $identifiers) . '.' . strtolower($extension);
    }
}
