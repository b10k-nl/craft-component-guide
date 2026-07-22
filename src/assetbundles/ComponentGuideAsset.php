<?php

namespace b10k\componentguide\assetbundles;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

/**
 * Control-panel assets for Component Guide (index + detail pages).
 */
class ComponentGuideAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = '@b10k/componentguide/web';
        $this->depends = [CpAsset::class];
        $this->css = ['css/component-guide.css'];
        $this->js = ['js/component-guide.js'];

        parent::init();
    }
}
