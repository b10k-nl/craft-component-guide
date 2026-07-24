<?php

namespace b10k\componentguide\assetbundles;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

/**
 * Matrix block-picker assets, loaded on every CP page (the JS no-ops unless a
 * Matrix field with matching components is present).
 */
class PickerAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = '@b10k/componentguide/web';
        $this->depends = [CpAsset::class];
        $this->css = ['css/picker.css'];
        $this->js = ['js/picker.js'];

        parent::init();
    }
}
