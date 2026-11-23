<?php

use GlpiPlugin\Assetsynccontroller\Access;

use GlpiPlugin\Assetsynccontroller\BillingConfiguration;
use GlpiPlugin\Assetsynccontroller\BillingFields;
use GlpiPlugin\Assetsynccontroller\BillingModels;
use GlpiPlugin\Assetsynccontroller\BillingRunner;
use GlpiPlugin\Assetsynccontroller\Menu;
use GlpiPlugin\Assetsynccontroller\SyncException;
use GlpiPlugin\Assetsynccontroller\SyncProblem;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

global $CFG_GLPI, $DB;

$billingUrl = $CFG_GLPI['root_doc'] . Menu::PAGE_PATH . '?section=billing-configuration';
$billing = BillingConfiguration::load();
$billingFields = [
    'status' => ['Status', 'dropdown'],
    'ownership' => ['Ownership', 'dropdown'],
    'installed_date' => ['Installed Date', 'date'],
    'model' => ['Model', 'model'],
    'hw_billable' => ['HW Billable', 'boolean'],
    'billing_start_date' => ['Billing Start Date', 'date'],
    'billing_end_date' => ['Billing End Date', 'date'],
    'frequency' => ['Frequency', 'dropdown'],
    'billing_month' => ['Billing Month', 'number'],
];
$swsdBillingFields = [
    'status' => ['Status', 'dropdown'],
    'redeployed_date' => ['Redeployed Date', 'date'],
    'swsd_billable' => ['SWSD Billable', 'boolean'],
    'billing_start_date' => ['Billing Start Date', 'date'],
];

$entityOptions = [];
$activeEntities = Session::getActiveEntities();
if ($activeEntities !== [])
{
    foreach ($DB->request([
        'SELECT' => ['id', 'name', 'completename'],
        'FROM' => Entity::getTable(),
        'WHERE' => ['id' => array_values($activeEntities)],
        'ORDER' => ['completename'],
    ]) as $entity)
    {
        $entityOptions[(int) $entity['id']] = $entity['completename'] ?: $entity['name'];
    }
}

// Deleted entities should never prevent an administrator from updating the saved scope.
$outsideScope = array_values(array_diff($billing['entities'], array_keys($entityOptions)));
$hasFullBillingScope = $outsideScope === [] || count($DB->request([
    'SELECT' => ['id'],
    'FROM' => Entity::getTable(),
    'WHERE' => ['id' => $outsideScope],
])) === 0;
if ($hasFullBillingScope && $outsideScope !== [])
{
    $billing['entities'] = array_values(array_intersect($billing['entities'], array_keys($entityOptions)));
}
$canSaveBilling = $hasFullBillingScope && Access::canUpdate('billing-configuration');
$assetTypes = BillingFields::assetTypes();

$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
$assetType = ($isPost ? $_POST : $_GET)['asset_type'] ?? '';
$assetType = is_string($assetType) && isset($assetTypes[$assetType]) ? $assetType : '';
$fieldOptions = [];
$modelOptions = [];
$modelKind = null;
$billingError = '';
if ($hasFullBillingScope && $billing['entities'] !== [])
{
    if ($assetType !== '')
    {
        $fieldOptions = BillingFields::forAssetType($assetType, $billing['entities']);
        $fieldOptions['model'] = $fieldOptions['dropdown'] + $fieldOptions['text'];
        // Model groups are only used by the Computer hardware calculation.
        $modelField = $assetType === Computer::class ? ($billing['fields'][$assetType]['model'] ?? null) : null;
        $currentModel = $fieldOptions['model'][$modelField['id'] ?? 0] ?? null;
        if ($currentModel !== null && $currentModel['table'] === $modelField['table']
            && $currentModel['field'] === $modelField['field']
            && ($currentModel['linkfield'] ?? null) === ($modelField['linkfield'] ?? null))
        {
            $modelKind = $currentModel['kind'];
            if ($modelKind === 'dropdown')
            {
                try
                {
                    $modelOptions = BillingModels::getOptions($modelField, $billing['entities']);
                }
                catch (SyncException | SyncProblem)
                {
                    // The saved field remains visible for repair when its dropdown metadata changes.
                    $modelKind = null;
                }
            }
        }
    }
}

if ($isPost)
{
    Access::checkUpdate('billing-configuration');
    $action = $_POST['billing_action'] ?? '';
    if (!in_array($action, ['entities', 'fields', 'swsd_fields', 'models'], true))
    {
        throw new BadRequestHttpException('Invalid billing configuration action.');
    }
    if (!$canSaveBilling)
    {
        throw new BadRequestHttpException('Open all of the saved billing entities in GLPI before editing.');
    }

    $updatedBilling = $billing;
    $workflowsToCheck = [];
    if ($action === 'entities')
    {
        $updatedBilling['entities'] = [];
        foreach ((array) ($_POST['billing_entities'] ?? []) as $value)
        {
            $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
            if ($id === false || !isset($entityOptions[$id]))
            {
                $billingError = 'Select GLPI A entities available in your current scope.';
                break;
            }
            $updatedBilling['entities'][] = $id;
        }
        $updatedBilling['entities'] = array_values(array_unique($updatedBilling['entities']));
        $workflowsToCheck = ['fields', 'swsd_fields'];
    }
    elseif ($billing['entities'] === [])
    {
        $billingError = 'Save at least one GLPI A entity first.';
    }
    elseif ($action === 'fields' || $action === 'swsd_fields')
    {
        if ($assetType === '')
        {
            throw new BadRequestHttpException('Select a valid GLPI A asset type.');
        }
        // Each section saves its own mappings, including the fields shared by both sections.
        if ($assetType === Computer::class)
        {
            $updatedBilling['enabled'][$action] = ($_POST['billing_enabled'] ?? '') === '1';
            $workflowsToCheck = [$action];
        }
        $sectionFields = $action === 'fields' ? $billingFields : $swsdBillingFields;
        foreach ($sectionFields as $key => [$label, $kind])
        {
            $value = $_POST['billing_field'][$key] ?? null;
            if ($value === '')
            {
                unset($updatedBilling[$action][$assetType][$key]);
                continue;
            }
            // Preserve a saved field that is unavailable until the administrator replaces or clears it.
            if ($value === 'saved' && isset($billing[$action][$assetType][$key]))
            {
                continue;
            }
            $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id === false || !isset($fieldOptions[$kind][$id]))
            {
                $billingError = "Select an available GLPI A field for $label.";
                break;
            }
            $updatedBilling[$action][$assetType][$key] = ['id' => $id] + $fieldOptions[$kind][$id];
        }
    }
    else
    {
        if ($assetType === '' || $modelKind === null)
        {
            throw new BadRequestHttpException('Save a GLPI A Model mapping before assigning billing frequencies.');
        }

        $labels = [];
        $selectedKeys = [];
        foreach (BillingConfiguration::FREQUENCIES as $frequency => $label)
        {
            if ($modelKind === 'dropdown')
            {
                $submittedKeys = (array) ($_POST['billing_models'][$frequency] ?? []);
                $submittedLabels = [];
                foreach ($submittedKeys as $key)
                {
                    if (!is_string($key) || !isset($modelOptions[$key]))
                    {
                        $billingError = 'Select models available in every saved billing entity.';
                        break 2;
                    }
                    $submittedLabels[] = $modelOptions[$key];
                    $selectedKeys[$key] = $frequency;
                }
            }
            else
            {
                $submittedText = $_POST['billing_model_labels'][$frequency] ?? '';
                if (!is_string($submittedText))
                {
                    throw new BadRequestHttpException('Invalid Model labels.');
                }
                $submittedLabels = preg_split('/\r\n|\r|\n/', $submittedText);
            }

            foreach ($submittedLabels as $modelLabel)
            {
                $modelLabel = trim($modelLabel);
                if ($modelLabel === '')
                {
                    continue;
                }
                if (isset($labels[$modelLabel]))
                {
                    $billingError = 'Each model label can be entered only once across Monthly and Annual.';
                    break 2;
                }
                $labels[$modelLabel] = $frequency;
            }
        }

        if ($modelKind === 'dropdown')
        {
            $updatedBilling['models'] = $selectedKeys + array_diff_key($billing['models'], $modelOptions);
            $updatedBilling['dropdown_model_labels'] = $labels;
        }
        else
        {
            $updatedBilling['model_labels'] = $labels;
        }
        $workflowsToCheck = ['fields'];
    }

    if ($billingError === '')
    {
        // Validate changed workflows before enabling them or changing their shared configuration.
        $workflowPlans = [];
        foreach ($workflowsToCheck as $workflow)
        {
            if (empty($updatedBilling['enabled'][$workflow]))
            {
                continue;
            }
            try
            {
                $workflowPlans[$workflow] = BillingRunner::validate($updatedBilling, $workflow);
            }
            catch (SyncProblem $problem)
            {
                $billingError = $problem->getMessage();
                break;
            }
            catch (SyncException)
            {
                $billingError = 'An enabled workflow has a billing field or dropdown option that is unavailable.';
                break;
            }
        }
        if ($billingError === '' && $workflowPlans !== []
            && !empty($updatedBilling['enabled']['fields']) && !empty($updatedBilling['enabled']['swsd_fields']))
        {
            try
            {
                $hardware = $workflowPlans['fields'] ?? BillingRunner::validate($updatedBilling, 'fields');
                $software = $workflowPlans['swsd_fields'] ?? BillingRunner::validate($updatedBilling, 'swsd_fields');
                foreach ($hardware as $entityId => $plan)
                {
                    if (BillingRunner::workflowsConflict(['fields' => $plan, 'swsd_fields' => $software[$entityId]]))
                    {
                        $billingError = 'Hardware and SW/SD must use separate output fields, and outputs cannot also be inputs.';
                        break;
                    }
                }
            }
            catch (SyncProblem | SyncException)
            {
                // An unrelated invalid workflow should not prevent saving a repair.
            }
        }
    }

    if ($billingError === '')
    {
        BillingConfiguration::save($updatedBilling);
        Session::addMessageAfterRedirect('Billing configuration saved.');
        $selection = $assetType === '' ? '' : '&asset_type=' . rawurlencode($assetType);
        Html::redirect($billingUrl . $selection, 303);
    }
}

$billingToken = $canSaveBilling ? Session::getNewCSRFToken() : '';
