<?php

namespace b10k\componentguide\controllers;

use b10k\componentguide\Plugin;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use yii\web\Response;

/**
 * Feeds the Matrix block picker (see web/js/picker.js).
 *
 * Returns the component catalog keyed for entry-type matching: a component
 * whose template base name equals a Matrix entry type handle (e.g.
 * `statsBar.twig` ↔ handle `statsBar`) becomes that block's gallery card.
 */
class PickerController extends Controller
{
    protected array|bool|int $allowAnonymous = false;

    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }
        $this->requireCpRequest();
        $this->requirePermission(Plugin::PERMISSION_ACCESS);
        return true;
    }

    public function actionMap(): Response
    {
        $components = [];

        foreach (Plugin::getInstance()->getRepository()->getAll() as $component) {
            $firstStory = $component->stories[0] ?? null;

            $components[] = [
                // `name` is the template base name — the entry-type handle candidate.
                'name' => $component->name,
                'title' => $component->title,
                'description' => $component->description,
                'status' => $component->status,
                'group' => $component->effectiveGroup(),
                'previewUrl' => $firstStory !== null
                    ? UrlHelper::cpUrl("component-guide/preview/{$component->id}/{$firstStory->id}")
                    : null,
                'detailUrl' => UrlHelper::cpUrl("component-guide/components/{$component->id}"),
            ];
        }

        return $this->asJson(['components' => $components]);
    }
}
