<?php
/**
 * @file
 * Provides ExternalModule class for REDCap Form Render Skip Logic.
 */

namespace FormRenderSkipLogic\ExternalModule;

use ExternalModules\AbstractExternalModule;
use ExternalModules\ExternalModules;
use Form;
use Piping;
use Project;
use Records;
use Survey;
use RCView;
use REDCap;

/**
 * ExternalModule class for REDCap Form Render Skip Logic.
 */
class ExternalModule extends AbstractExternalModule {
    static protected $deniedForms;

    function redcap_every_page_before_render($project_id) {
        define('FORM_RENDER_SKIP_LOGIC_PREFIX', $this->PREFIX);
    }

    /**
     * @inheritdoc
     */
    function redcap_every_page_top($project_id) {
        if (!$project_id) {
            return;
        }

        if (strpos(PAGE, 'ExternalModules/manager/project.php') !== false) {
            $this->setJsSettings(array('modulePrefix' => $this->PREFIX, 'helperButtons' => $this->getPipingHelperButtons()));
            $this->includeJs('js/config.js');
            $this->includeCss('css/config.css');

            return;
        }

        switch (PAGE) {
            case 'DataEntry/record_home.php':
                $args_order = array('pid', 'id', 'event_id', 'page');
                break;

            case 'DataEntry/record_status_dashboard.php':
                $args_order = array('pid', 'id', 'page', 'event_id', 'instance');
                break;

            default:
                return;

        }

        $this->loadBulletsHandler($args_order, $this->getNumericQueryParam('arm', 1), $this->getNumericQueryParam('id'));
    }

    /**
     * @inheritdoc
     */
    function redcap_data_entry_form_top($project_id, $record = null, $instrument, $event_id, $group_id = null) {
        global $Proj;

        if (empty($record)) {
            $record = $this->getNumericQueryParam('id');
        }

        $this->loadBulletsHandler(array('pid', 'page', 'id', 'event_id'), $Proj->eventInfo[$event_id]['arm_num'], $record, $event_id, $instrument);
        $this->loadButtonsHandler($record, $event_id, $instrument);
    }

    /**
     * @inheritdoc
     */
    function redcap_survey_page_top($project_id, $record = null, $instrument, $event_id, $group_id = null, $survey_hash, $response_id = null, $repeat_instance = 1) {
        if (empty($record)) {
            $record = $this->getNumericQueryParam('id');
        }

        $this->overrideSurveysStatuses($record, $event_id);

        global $Proj;
        $survey_id = $Proj->forms[$instrument]['survey_id'];
        if ($Proj->surveys[$survey_id]['survey_enabled']) {
            return;
        }

        // Access denied for this survey.
        if (!$redirect_url = Survey::getAutoContinueSurveyUrl($record, $form_name, $event_id, $repeat_instance)) {
            $redirect_url = APP_PATH_WEBROOT;
        }

        $this->redirect($redirect_url);
    }

    /**
     * @inheritdoc
     */
    function redcap_save_record($project_id, $record = null, $instrument, $event_id, $group_id = null, $survey_hash = null, $response_id = null, $repeat_instance = 1) {
        if ($survey_hash) {
            $this->overrideSurveysStatuses($record, $event_id);
        }
    }

    function loadBulletsHandler($args_order, $arm, $record = null, $event_id = null, $form = null) {
        $args = array_combine($args_order, array_fill(0, count($args_order), '1'));
        $args['pid'] = PROJECT_ID;

        $selectors = array();
        foreach ($this->getDeniedForms($arm, $record, $event_id, $form) as $id => $events) {
            $args['id'] = $id;

            foreach ($events as $event_id => $forms) {
                $args['event_id'] = $event_id;

                foreach ($forms as $page) {
                    $args['page'] = $page;
                    $selectors[] = 'a[href^="' . APP_PATH_WEBROOT . 'DataEntry/index.php?' . http_build_query($args) . '"]';
                }
            }
        }

        if (!empty($selectors)) {
            echo '<style>' . implode(', ', $selectors) . ' { display: none; }</style>';
        }
    }

    /**
     * Gets access denied forms.
     *
     * @param string $arm
     *   The arm name.
     * @param int $record
     *   The data entry record ID.
     *
     * @return array
     *   The forms access matrix. The array is keyed as follows:
     *   - record ID
     *   -- event ID
     *   --- instrument name: TRUE/FALSE
     */
    function getDeniedForms($arm, $record = null) {
        if (isset(self::$deniedForms)) {
            return self::$deniedForms;
        }

        global $Proj;

        // Getting events of the current arm.
        $events = array_keys($Proj->events[$arm]['events']);

        $target_forms = array();
        $settings = $this->getFormattedSettings($Proj->project_id);

        $control_fields = array();
        $control_fields_keys = array();

        $i = 0;
        foreach ($settings['control_fields'] as $cf) {
            if ($cf['control_mode'] == 'default' && (!$cf['control_event_id'] || !$cf['control_field_key'])) {
                // Checking for required fields in default mode.
                continue;
            }

            if ($cf['control_mode'] == 'advanced' && !$cf['control_piping']) {
                // Checking for required fields in advanced mode.
                continue;
            }

            $control_fields_keys[] = $cf['control_field_key'];
            if (empty($cf['control_default_value']) && !is_numeric($cf['control_default_value'])) {
                $cf['control_default_value'] = '';
            }

            $branching_logic = $cf['branching_logic'];
            unset($cf['branching_logic']);

            foreach ($branching_logic as $bl) {
                if (empty($bl['target_forms'])) {
                    continue;
                }

                $control_fields[$i] = $cf + $bl;
                $target_events = $bl['target_events_select'] ? $bl['target_events'] : $events;

                foreach ($target_events as $event_id) {
                    if (!isset($target_forms[$event_id])) {
                        $target_forms[$event_id] = array();
                    }

                    foreach ($bl['target_forms'] as $form) {
                        if (!isset($target_forms[$event_id][$form])) {
                            $target_forms[$event_id][$form] = array();
                        }

                        $target_forms[$event_id][$form][] = $i;
                    }
                }

                $i++;
            }
        }

        $control_data = REDCap::getData($Proj->project_id, 'array', $record, $control_field_keys);
        if ($record && !isset($control_data[$record])) {
            // Handling new record case.
            $control_data = array($record => array());
        }

        // Building forms access matrix.
        $denied_forms = array();
        foreach ($control_data as $id => $data) {
            $control_values = array();
            foreach ($control_fields as $i => $cf) {
                $ev = $cf['control_event_id'];
                $fd = $cf['control_field_key'];

                $a = $cf['control_default_value'];
                $b = $cf['condition_value'];

                if ($cf['control_mode'] == 'advanced') {
                    $piped = Piping::replaceVariablesInLabel($cf['control_piping'], $id, $event_id, 1, array(), true, null, false);
                    if ($piped !== '') {
                        $a = $piped;
                    }
                }
                else {
                    if (isset($data[$ev][$fd]) && Records::formHasData($id, $Proj->metadata[$fd]['form_name'], $ev)) {
                        $a = $data[$ev][$fd];
                    }
                }

                switch ($cf['condition_operator']) {
                    case '>':
                        $matches = $a > $b;
                        break;
                    case '>=':
                        $matches = $a >= $b;
                        break;
                    case '<':
                        $matches = $a < $b;
                        break;
                    case '<=':
                        $matches = $a <= $b;
                        break;
                    case '<>':
                        $matches = $a !== $b;
                        break;
                    default:
                        $matches = $a === $b;
                }

                $control_values[$i] = $matches;
            }

            $denied_forms[$id] = array();

            foreach ($events as $event_id) {
                $denied_forms[$id][$event] = array();

                foreach ($Proj->eventsForms[$event_id] as $form) {
                    $access = true;

                    if (isset($target_forms[$event_id][$form])) {
                        $access = false;

                        foreach ($target_forms[$event_id][$form] as $i) {
                            if ($control_values[$i]) {
                                // If one condition is satisfied, the form
                                // should be displayed.
                                $access = true;
                                break;
                            }
                        }
                    }

                    if (!$access) {
                        $denied_forms[$id][$event_id][$form] = $form;
                    }
                }
            }
        }

        self::$deniedForms = $denied_forms;
        return $denied_forms;
    }

    /**
     * Loads main feature functionality.
     *
     * @param string $location
     *   The location to apply FRSL. Can be:
     *   - data_entry_form
     *   - record_home
     *   - record_status_dashboard
     *   - survey
     * @param int $record
     *   The data entry record ID.
     * @param int $event_id
     *   The event ID. Only required when $location = "data_entry_form".
     * @param string $instrument
     *   The form/instrument name.
     */
    protected function loadButtonsHandler($record = null, $event_id = null, $instrument = null) {
        global $Proj;

        $arm = $event_id ? $Proj->eventInfo[$event_id]['arm_num'] : $this->getNumericQueryParam('arm', 1);
        $next_step_path = '';
        $denied_forms = self::$deniedForms;

        if ($record && $event_id && $instrument) {
            $instruments = $Proj->eventsForms[$event_id];
            $curr_denied_forms = $denied_forms[$record][$event_id];

            $i = array_search($instrument, $instruments) + 1;
            $len = count($instruments);

            while ($i < $len) {
                if (empty($curr_denied_forms[$instruments[$i]])) {
                    $next_instrument = $instruments[$i];
                    break;
                }

                $i++;
            }

            if (isset($next_instrument)) {
                // Path to the next available form in the current event.
                $next_step_path = APP_PATH_WEBROOT . 'DataEntry/index.php?pid=' . $Proj->project_id . '&id=' . $record . '&event_id=' . $event_id . '&page=' . $next_instrument;
            }

            // Access denied to the current page.
            if (!empty($denied_forms[$record][$event_id][$instrument])) {
                if (!$next_step_path) {
                    $next_step_path = APP_PATH_WEBROOT . 'DataEntry/record_home.php?pid=' . $Proj->project_id . '&id=' . $record . '&arm=' . $arm;
                }

                $this->redirect($next_step_path);
                return;
            }
        }

        $this->setJsSettings(array('nextStepPath' => $next_step_path));
        $this->includeJs('js/frsl.js');
    }

    /**
     * Checks for non authorized surveys and disables them for the current
     * request.
     *
     * @param int $record
     *   The data entry record ID.
     * @param int $event_id
     *   The event ID.
     */
    protected function overrideSurveysStatuses($record, $event_id) {
        global $Proj;

        $arm = $Proj->eventInfo[$event_id]['arm_num'];
        $denied_forms = $this->getDeniedForms($arm, $record);

        foreach ($denied_forms[$record][$event_id] as $form) {
            if (empty($Proj->forms[$form]['survey_id'])) {
                continue;
            }

            // Disabling surveys that are not allowed.
            $survey_id = $Proj->forms[$form]['survey_id'];
            $Proj->surveys[$survey_id]['survey_enabled'] = 0;
        }
    }

    /**
     * Formats settings into a hierarchical key-value pair array.
     *
     * @param int $project_id
     *   Enter a project ID to get project settings.
     *   Leave blank to get system settings.
     *
     * @return array
     *   The formatted settings.
     */
    function getFormattedSettings($project_id = null) {
        $settings = $this->getConfig();

        if ($project_id) {
            $settings = $settings['project-settings'];
            $values = ExternalModules::getProjectSettingsAsArray($this->PREFIX, $project_id);
        }
        else {
            $settings = $settings['system-settings'];
            $values = ExternalModules::getSystemSettingsAsArray($this->PREFIX);
        }

        return $this->_getFormattedSettings($settings, $values);
    }

    /**
     * Gets numeric URL query parameter.
     *
     * @param string $param
     *   The parameter name
     * @param mixed $default
     *   The default value if query parameter is not available.
     *
     * @return mixed
     *   The parameter from URL if available. The default value provided is
     *   returned otherwise.
     */
    function getNumericQueryParam($param, $default = null) {
        return empty($_GET[$param]) || intval($_GET[$param]) != $_GET[$param] ? $default : $_GET[$param];
    }

    /**
     * Redirects user to the given URL.
     *
     * This function basically replicates redirect() function, but since EM
     * throws an error when an exit() is called, we need to adapt it to the
     * EM way of exiting.
     */
    protected function redirect($url) {
        if (headers_sent()) {
            // If contents already output, use javascript to redirect instead.
            echo '<script>window.location.href="' . $url . '";</script>';
        }
        else {
            // Redirect using PHP.
            header('Location: ' . $url);
        }

        $this->exitAfterHook();
    }

    /**
     * Includes a local JS file.
     *
     * @param string $path
     *   The relative path to the js file.
     */
    protected function includeJs($path) {
        echo '<script src="' . $this->getUrl($path) . '"></script>';
    }

    /**
     * Includes a local CSS file.
     *
     * @param string $path
     *   The relative path to the css file.
     */
    protected function includeCss($path) {
        echo '<link rel="stylesheet" href="' . $this->getUrl($path) . '">';
    }

    /**
     * Sets JS settings.
     *
     * @param array $settings
     *   A keyed array containing settings for the current page.
     */
    protected function setJsSettings($settings) {
        echo '<script>formRenderSkipLogic = ' . json_encode($settings) . ';</script>';
    }

    /**
     * Gets Piping helper buttons.
     */
    protected function getPipingHelperButtons() {
        global $lang;

        $this->includeCss('css/piping-helper.css');
        $buttons = array(
            'green' => array(
                'callback' => 'smartVariableExplainPopup',
                'contents' => '[<i class="fas fa-bolt fa-xs"></i>] ' . $lang['global_146'],
            ),
            'purple' => array(
                'callback' => 'pipingExplanation',
                'contents' => RCView::img(array('src' => APP_PATH_IMAGES . 'pipe.png')) . $lang['info_41'],
            ),
        );

        $output = '';
        foreach ($buttons as $color => $btn) {
            $output .= RCView::button(array('class' => 'btn btn-xs btn-rc' . $color . ' btn-rc' . $color . '-light', 'onclick' => $btn['callback'] . '(); return false;'), $btn['contents']);
        }

        return RCView::span(array('class' => 'frsl-piping-helper'), $output);
    }

    /**
     * Auxiliary function for getFormattedSettings().
     */
    protected function _getFormattedSettings($settings, $values, $inherited_deltas = array()) {
        $formatted = array();

        foreach ($settings as $setting) {
            $key = $setting['key'];
            $value = $values[$key]['value'];

            foreach ($inherited_deltas as $delta) {
                $value = $value[$delta];
            }

            if ($setting['type'] == 'sub_settings') {
                $deltas = array_keys($value);
                $value = array();

                foreach ($deltas as $delta) {
                    $sub_deltas = array_merge($inherited_deltas, array($delta));
                    $value[$delta] = $this->_getFormattedSettings($setting['sub_settings'], $values, $sub_deltas);
                }

                if (empty($setting['repeatable'])) {
                    $value = $value[0];
                }
            }

            $formatted[$key] = $value;
        }

        return $formatted;
    }
}
