<?php

class ActiveRecordUploadableImageBehavior extends CActiveRecordBehavior {
    /**
     * Формат данных
     * array(
     *     'avatarImage' => array(
     *         'urlAtribute' => 'avatarImageUrl',                   // not required
     *         'onUploadAttribute' => 'onAvatarUploaded',           // not required
     *         'imagePathGetter' => '',
     *         'imageUrlGetter' => '',
     *         'defaultSize' => array ('width' => x, 'height' => y) || ('maxWidth' => x, 'maxHeight' => y),
     *              || ('minWidth' => x, 'minHeight'=>y)            // not required
     *
     *         'defaultImageUrl' => '',                             // not required
     *     ),
     *    'otherImage' => array(...),
     *     ...
     * )
     *
     * @var array
     */
    public $imageAttributes = array();

    public function beforeValidate($event) {
        foreach($this->imageAttributes as $key => $attributeConfig) {
            if (!$this->owner->{$key} instanceof CUploadedFile)
                $this->owner->{$key} = CUploadedFile::getInstance($this->owner, $key);
            if ($this->owner->{$key}) {
                try {
                    Yii::app()->image->load($this->owner->{$key}->getTempName());
                    if (isset($attributeConfig['onUploadAttribute']))
                        $this->owner->{$attributeConfig['onUploadAttribute']} = time();
                    if (isset($attributeConfig['urlAttribute']) && $this->owner->{$key} && !$this->owner->{$attributeConfig['urlAttribute']})
                        $this->owner->{$attributeConfig['urlAttribute']} = $attributeConfig['defaultImageUrl'];
                } catch (CException $e) {
                    $this->owner->addError($key, $e->getMessage());
                    $event->isValid = false;
                }
            }
            if (!$event->isValid)
                break;
        }
    }

    public function afterSave($event) {
        foreach($this->imageAttributes as $key => $attributeConfig) {
            if ($this->owner->{$key}) {
                $saveDir = dirname($this->owner->{$attributeConfig['imagePathGetter']}());
                if (!(is_dir($saveDir) || mkdir($saveDir, 0775, true))) {
                    throw new CException('Нет доступа на запись в директорию картинок.');
                }

                /** @var Image $imageFile */
                $imageFile = Yii::app()->image->load($this->owner->{$key}->getTempName());
                $sizeProportion = $imageFile->width / $imageFile->height;

                /**
                 * Алгоритм рассчитывает ресайз картинки в соответствии с заданными параметрами в конфигурации
                 * Код рассчитан на одновременное указания только конкретного размера или максимального размера
                 * или минимального размера. Например если одновременно указать maxWidth и minWidth или width
                 * и maxHeight то результат может не соответствовать указанным параметрам.
                 */
                if (isset($attributeConfig['defaultSize'])) {
                    $imageSize = $attributeConfig['defaultSize'];

                    if (isset($imageSize['width'])) {
                        // ресайз под ширину и если указано высоту
                        if (isset($imageSize['height'])) {
                            $imageFile->resize($imageSize['width'], $imageSize['height']);
                        } else {
                            $imageFile->resize($imageSize['width'], (int)($imageSize['width']/$sizeProportion));
                        }
                    } else if(isset($imageSize['height'])) {
                        // ресайз под высоту
                        $imageFile->resize((int)($imageSize['height']*$sizeProportion), $imageSize['height']);
                    } else if(isset($imageSize['maxWidth']) && $imageFile->width > $imageSize['maxWidth']) {
                        // ресайз под макс. ширину и если указано макс. высоту
                        $planedWidth = $imageSize['maxWidth'];
                        $planedHeight = $imageSize['maxWidth']/$sizeProportion;
                        if (isset($imageSize['maxHeight']) && $planedHeight > $imageSize['maxHeight']) {
                            $planedHeight = $imageSize['maxHeight'];
                            $planedWidth = (int)($planedHeight * $sizeProportion);
                        }
                        $imageFile->resize($planedWidth, $planedHeight);
                    } else if(isset($imageSize['maxHeight']) && $imageFile->height > $imageSize['maxHeight']) {
                        // ресайз под макс. высоту
                        $imageFile->resize((int)($imageSize['maxHeight']*$sizeProportion), $imageSize['maxHeight']);
                    } else if(isset($imageSize['mixWidth']) && $imageFile->width < $imageSize['mixWidth']) {
                        // ресайз под мин. ширину и если указано мин. высоту
                        $planedWidth = $imageSize['mixWidth'];
                        $planedHeight = (int)($imageSize['minWidth']/$sizeProportion);
                        if (isset($imageSize['minHeight']) && $planedHeight < $imageSize['minHeight']) {
                            $planedHeight = $imageSize['minHeight'];
                            $planedWidth = (int)($planedHeight*$sizeProportion);
                        }
                        $imageFile->resize($planedWidth, $planedHeight);
                    } else if (isset($imageSize['minHeight']) && $imageFile->height < $imageSize['minHeight']) {
                        $imageFile->resize((int)($imageSize['minHeight']*$sizeProportion), $imageSize['minHeight']);
                    }
                }

                $imageFilename = $this->owner->{$attributeConfig['imagePathGetter']}();
                if (is_file($imageFilename))
                    unlink($imageFilename);

                $imageFile->save($imageFilename);
                if (method_exists($this->owner, 'onAfterImageSave')) {
                    $this->owner->onAfterImageSave(array(
                        'image' => $imageFile,
                        'sourceFilename' => $this->owner->{$key}->getTempName(),
                    ));
                }

                if (isset($attributeConfig['urlAttribute'])) {
                    $updateAttributes = array();
                    $updateAttributes[$attributeConfig['urlAttribute']] = $this->owner->{$attributeConfig['imageUrlGetter']}();
                    $this->owner->update($updateAttributes);
                }
            }
        }
    }

    public function afterDelete($event) {
        foreach($this->imageAttributes as $key => $attributeConfig) {
            $imageFilename = $this->owner->{$attributeConfig['imagePathGetter']}();
            if (is_file($imageFilename))
                unlink($imageFilename);
        }
    }
}
