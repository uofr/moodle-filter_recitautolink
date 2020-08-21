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

// Activity name filtering.

/**
 * This filter must be put before Auto-linking with Manage Filters to work properly.
 *
 * @package    filter_recitactivity
 * @copyright  RECITFAD
 * @author     RECITFAD
 * @license    {@link http://www.gnu.org/licenses/gpl-3.0.html} GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Main class for filtering text.
 *
 * Attention: do not utilise the global variables $PAGE and $COURSE. Instead, use $this->page and $this->page->course.
 * When the filter is used by some ajax service (like TreeTopics) the global variables are not set as it should but $this->page is so.
 * 
 * @copyright   RECITFAD
 * @license    {@link http://www.gnu.org/licenses/gpl-3.0.html} GNU GPL v3 or later
 */
class filter_recitactivity extends moodle_text_filter {
    /** @var array course activities list */
    protected $courseactivitieslist = array();
    /** @var array teachers list */
    protected $teacherslist = array();
    /** @var object */
    protected $page = null;
    /** @var object */
    protected $mysqli = null;

    /**
     * This function gets all teachers for a course.
     *
     * @param int $courseid
     */
    protected function load_course_teachers($courseid) {
        if(count($this->teacherslist) > 0){ return; }

        $query = "select t1.id as id, t1.firstname, t1.lastname, t1.email, t5.shortname as role, concat(t1.firstname, ' ', t1.lastname) as imagealt,
        t1.picture, t1.firstnamephonetic, t1.lastnamephonetic, t1.middlename, t1.alternatename   
        from mdl_user as t1  
        inner join mdl_user_enrolments as t2 on t1.id = t2.userid
        inner join mdl_enrol as t3 on t2.enrolid = t3.id
        inner join mdl_role_assignments as t4 on t1.id = t4.userid and t4.contextid in (select id from mdl_context where instanceid = $courseid)
        inner join mdl_role as t5 on t4.roleid = t5.id and t5.shortname in ('teacher', 'editingteacher', 'noneditingteacher')
        where t3.courseid = $courseid";
		
		$rst = $this->mysqli->query($query);
		
		$this->teacherslist = array();
		while($obj = $rst->fetch_object()){
			$this->teacherslist[] = $obj;
		}
    }

    /**
     * Setup function loads teachers and activities.
     *
     * {@inheritDoc}
     * @see moodle_text_filter::setup()
     * @param object $page
     * @param object $context
     */
    public function setup($page, $context) {
        global $DB;

        $this->page = $page;

        // this filter is only applied where the courseId is greater than 1, it means, a real course.
        if($this->page->course->id <= 1){
            return;
        }

		$moodleDB = $DB;
		$refMoodleDB = new ReflectionObject($moodleDB);
		$refProp1 = $refMoodleDB->getProperty('mysqli');
		$refProp1->setAccessible(TRUE);
		$this->mysqli = $refProp1->getValue($moodleDB);

        $this->load_course_teachers($this->page->course->id);

        $this->load_course_activities_list();
    }

    /**
     * Get array variable course activities list
     */
    protected function load_course_activities_list() {
        global $USER;

        if(count($this->courseactivitieslist) > 0){ return;}

        $this->courseactivitieslist = array();

        $modinfo = get_fast_modinfo($this->page->course);

        if (empty($modinfo->cms)) {
            return;
        }

        $query = "SELECT cmc.* FROM mdl_course_modules as cm
                INNER JOIN mdl_course_modules_completion cmc ON cmc.coursemoduleid=cm.id 
                WHERE cm.course={$this->page->course->id} AND cmc.userid=$USER->id";
		
		$rst = $this->mysqli->query($query);
		
		$cmCompletions = array();
		while($obj = $rst->fetch_object()){
			$cmCompletions[$obj->coursemoduleid] = $obj;
		}

        $avoidModules = array("label");

        foreach ($modinfo->cms as $cm) {
            if(in_array($cm->__get('modname'), $avoidModules)){
                continue;
            }

            // Use normal access control and visibility, but exclude labels and hidden activities.
            if (!$cm->has_view()) {
                continue;
            }

            $title = s(trim(strip_tags($cm->__get('name'))));
            $currentname = trim($cm->__get('name'));

            // Avoid empty or unlinkable activity names.
            if (empty($title) || ($cm->deletioninprogress == 1)) {
                continue;
            }
            
            $cmname = $this->get_cm_name($cm);

            // Row not present counts as 'not complete'
            $completiondata = new stdClass();
            $completiondata->id = 0;
            $completiondata->coursemoduleid = $cm->__get('id');
            $completiondata->userid = $USER->id;
            $completiondata->completionstate = 0;
            $completiondata->viewed = 0;
            $completiondata->overrideby = null;
            $completiondata->timemodified = 0;

            if(isset($cmCompletions[$cm->__get('id')])){
                $completiondata = $cmCompletions[$cm->__get('id')];
            }

            $cmcompletion = $this->course_section_cm_completion($cm, $completiondata);
            $isrestricted = ($cm->__get('uservisible') & !empty($cm->availableinfo));

            $courseactivity = new stdClass();
            $courseactivity->cmname = $cmname;
            $courseactivity->currentname = $currentname;
            $courseactivity->cmcompletion = $cmcompletion;
            $courseactivity->id = $cm->__get('id');
            $courseactivity->uservisible = $cm->uservisible;

            if($isrestricted){
                $courseactivity->href_tag_begin = html_writer::start_tag('a', array('class' => 'autolink disabled ',
                    'title' => $title, 'href' => '#'));
                $courseactivity->href_tag_end = '</a>';
                $courseactivity->cmname = "<a class='disabled' href='#'>$title</a>";
                $courseactivity->cmcompletion = "";
            }
            else{
                $courseactivity->href_tag_begin = html_writer::start_tag('a', array('class' => 'autolink ',
                'title' => $title, 'href' => $cm->__get('url')));
                $courseactivity->href_tag_end = '</a>';
            }
            

            $this->courseactivitieslist[] = $courseactivity;
        }
    }

    protected function get_cm_name(cm_info $mod) {
        $output = '';
        $url = $mod->__get('url');
        //if (!$mod->is_visible_on_course_page() || !$url) {
        if (!$url) {
            // Nothing to be displayed to the user.
            return $output;
        }

        //Accessibility: for files get description via icon, this is very ugly hack!
        $instancename = $mod->__get('name'); //$mod->get_formatted_name();
        $altname = $mod->__get('modfullname');
        // Avoid unnecessary duplication: if e.g. a forum name already
        // includes the word forum (or Forum, etc) then it is unhelpful
        // to include that in the accessible description that is added.
        if (false !== strpos(core_text::strtolower($instancename),
                core_text::strtolower($altname))) {
            $altname = '';
        }
        // File type after name, for alphabetic lists (screen reader).
        if ($altname) {
            $altname = get_accesshide(' '.$altname);
        }

        // Get on-click attribute value if specified and decode the onclick - it
        // has already been encoded for display (puke).
        $onclick = htmlspecialchars_decode($mod->__get('onclick'), ENT_QUOTES);

        // Display link itself.
        $activitylink = html_writer::empty_tag('img', array('src' => $mod->get_icon_url(),
                'class' => 'iconlarge activityicon', 'alt' => '', 'role' => 'presentation', 'aria-hidden' => 'true')) .
                html_writer::tag('span', $instancename . $altname, array('class' => 'instancename'));
        if ($mod->__get('uservisible')) {
            $output .= html_writer::link($url, $activitylink, array('class' => 'aalink', 'onclick' => $onclick));
        } else {
            // We may be displaying this just in order to show information
            // about visibility, without the actual link ($mod->is_visible_on_course_page()).
            $output .= html_writer::tag('div', $activitylink);
        }
        return $output;
    }

    /**
     * Extract activity by name.
     *
     * @param string $name
     * @return $item from array course activities list|null
     */
    protected function get_course_activity($name) {
        foreach ($this->courseactivitieslist as $item) {
            if ($item->currentname == $name) {
                return $item;
            }
        }

        return null;
    }

    /**
     * Main filter function.
     *
     * {@inheritDoc}
     * @see moodle_text_filter::filter()
     * @param string $text
     * @param array $options
     */
    public function filter($text, array $options = array()) {
        global $USER, $OUTPUT, $COURSE;

        // this filter is only applied where the courseId is greater than 1, it means, a real course.
        if($this->page->course->id <= 1){
            return $text;
        }

        // Check if we need to build filters.
        if (strpos($text, '[[') === false or !is_string($text) or empty($text)) {
            return $text;
        }

        $matches = array();

        $sep = get_config('filter_recitactivity', 'character');

        preg_match_all('#(\[\[)([^\]]+)(\]\])#', $text, $matches);

        $matches = $matches[0]; // It will match the wanted RE, for instance [[i/Activité 3]].

        $result = $text;
        foreach ($matches as $match) {
            $item = explode($sep, $match);

            // In case "[[ActivityName]]".
            if (count($item) == 1) {
                $item[0] = str_replace("[[", "", $item[0]);
                $complement = str_replace("]]", "", $item[0]);
                $param = "l";
            } else {
                $complement = str_replace("]]", "", array_pop($item));
                $param = str_replace("[[", "", implode("", $item));
            }

            switch ($param) {
                case "i":
                    $activity = $this->get_course_activity($complement);
                    if ($activity != null) {
                        $result = str_replace($match, $activity->cmname, $result);
                    }
                    break;
                case "c":
                    $activity = $this->get_course_activity($complement);
                    if ($activity != null) {
                        $result = str_replace($match, sprintf("%s %s %s %s", $activity->cmcompletion,
                                $activity->href_tag_begin, $activity->currentname, $activity->href_tag_end), $result);
                    }
                    break;
                case "ci":
                case "ic":
                    $activity = $this->get_course_activity($complement);
                    if ($activity != null) {
                        $result = str_replace($match, sprintf("%s %s", $activity->cmcompletion, $activity->cmname), $result);
                    }
                    break;
                case "l":
                    $activity = $this->get_course_activity($complement);
                    if ($activity != null) {
                        $result = str_replace($match, sprintf("%s%s%s", $activity->href_tag_begin, $activity->currentname,
                                $activity->href_tag_end), $result);
                    }
                    break;
                case "d":
                    if ($complement == "user.firstname") {
                        $result = str_replace($match, $USER->firstname, $result);
                    } else if ($complement == "user.lastname") {
                        $result = str_replace($match, $USER->lastname, $result);
                    } else if ($complement == "user.email") {
                        $result = str_replace($match, $USER->email, $result);
                    } else if ($complement == "user.picture") {
                        $picture = $OUTPUT->user_picture($USER, array('courseid' => $this->page->course->id, 'link' => false));
                        $result = str_replace($match, $picture, $result);
                    } else if ($complement == "course.shortname") {
                        $result = str_replace($match, $COURSE->shortname, $result);
                    } else if ($complement == "course.fullname") {
                        $result = str_replace($match, $COURSE->fullname, $result);
                    } else {
                        foreach ($this->teacherslist as $index => $teacher) {
                            $nb = $index + 1;
                            if ($complement == "teacher$nb.firstname") {
                                $result = str_replace($match, $teacher->firstname, $result);
                            } else if ($complement == "teacher$nb.lastname") {
                                $result = str_replace($match, $teacher->lastname, $result);
                            } else if ($complement == "teacher$nb.email") {
                                $result = str_replace($match, $teacher->email, $result);
                            } else if ($complement == "teacher$nb.picture") {
                                $picture = $OUTPUT->user_picture($teacher, array('courseid' => $this->page->course->id,
                                    'link' => false));
                                $result = str_replace($match, $picture, $result);
                            }
                        }
                    }
                    break;
            }
        }

        return $result;
    }

    public function course_section_cm_completion(cm_info $mod, $completiondata) {
        global $CFG, $PAGE;
        $course = $this->page->course;

        $output = '';
        if (!$mod->__get('uservisible')) {
            return $output;
        }
        
        $completion = $mod->__get('completion'); // Return course-module completion value

        // First check global completion
        if (!isset($CFG->enablecompletion) || $CFG->enablecompletion == COMPLETION_DISABLED) {
            $completion = COMPLETION_DISABLED;
        }
        else if ($course->enablecompletion == COMPLETION_DISABLED) {   // Check course completion
            $completion = COMPLETION_DISABLED;
        }
        
        if ($completion == COMPLETION_TRACKING_NONE) {
            if ($PAGE->user_is_editing()) {
                $output .= html_writer::span('&nbsp;', 'filler');
            }
            return $output;
        }

        $completionicon = '';

        if ($PAGE->user_is_editing()) {
            switch ($completion) {
                case COMPLETION_TRACKING_MANUAL :
                    $completionicon = 'manual-enabled';
                    break;
                case COMPLETION_TRACKING_AUTOMATIC :
                    $completionicon = 'auto-enabled';
                    break;
            }
        } else if ($completion == COMPLETION_TRACKING_MANUAL) {
            switch ($completiondata->completionstate) {
                case COMPLETION_INCOMPLETE:
                    $completionicon = 'manual-n' . ($completiondata->overrideby ? '-override' : '');
                    break;
                case COMPLETION_COMPLETE:
                    $completionicon = 'manual-y' . ($completiondata->overrideby ? '-override' : '');
                    break;
            }
        } else { // Automatic.
            switch ($completiondata->completionstate) {
                case COMPLETION_INCOMPLETE:
                    $completionicon = 'auto-n' . ($completiondata->overrideby ? '-override' : '');
                    break;
                case COMPLETION_COMPLETE:
                    $completionicon = 'auto-y' . ($completiondata->overrideby ? '-override' : '');
                    break;
                case COMPLETION_COMPLETE_PASS:
                    $completionicon = 'auto-pass';
                    break;
                case COMPLETION_COMPLETE_FAIL:
                    $completionicon = 'auto-fail';
                    break;
            }
        }
        if ($completionicon) {
            //$formattedname = html_entity_decode($mod->get_formatted_name(), ENT_QUOTES, 'UTF-8');
            $formattedname = html_entity_decode($mod->__get('name'), ENT_QUOTES, 'UTF-8');
            $imgalt = $formattedname;
            /*if ($completiondata->overrideby) {
                $args = new stdClass();
                $args->modname = $formattedname;
                $overridebyuser = \core_user::get_user($completiondata->overrideby, '*', MUST_EXIST);
                $args->overrideuser = fullname($overridebyuser);
                $imgalt = get_string('completion-alt-' . $completionicon, 'completion', $args);
            } else {
                $imgalt = get_string('completion-alt-' . $completionicon, 'completion', $formattedname);
            }*/

            if ($PAGE->user_is_editing()) {
                // When editing, the icon is just an image.
                $completionpixicon = new pix_icon('i/completion-'.$completionicon, $imgalt, '',
                    array('title' => $imgalt, 'class' => 'iconsmall'));
                $output .= html_writer::tag('span', $this->renderPixIcon($completionpixicon),
                    array('class' => 'autocompletion'));
            } else if ($completion == COMPLETION_TRACKING_MANUAL) {
                $newstate =
                $completiondata->completionstate == COMPLETION_COMPLETE
                ? COMPLETION_INCOMPLETE
                : COMPLETION_COMPLETE;
                // In manual mode the icon is a toggle form...

                // If this completion state is used by the
                // conditional activities system, we need to turn
                // off the JS.
                $extraclass = '';
                if (!empty($CFG->enableavailability) &&
                        core_availability\info::completion_value_used($course, $mod->__get('id'))) {
                    $extraclass = ' preventjs';
                }
                $output .= html_writer::start_tag('form', array('method' => 'post',
                    'action' => new moodle_url('/course/togglecompletion.php'),
                    'class' => 'togglecompletion'. $extraclass, 'style' => 'display: inline;'));
                $output .= html_writer::start_tag('div', array('style' => 'display: inline;'));
                $output .= html_writer::empty_tag('input', array(
                    'type' => 'hidden', 'name' => 'id', 'value' => $mod->__get('id')));
                $output .= html_writer::empty_tag('input', array(
                    'type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()));
                $output .= html_writer::empty_tag('input', array(
                    'type' => 'hidden', 'name' => 'modulename', 'value' => $formattedname));
                $output .= html_writer::empty_tag('input', array(
                    'type' => 'hidden', 'name' => 'completionstate', 'value' => $newstate));
                    
                $completionpixicon = new pix_icon('i/completion-'.$completionicon, $imgalt, '');

                $output .= html_writer::tag('button', $this->renderPixIcon($completionpixicon), array('class' => 'btn btn-link', 'aria-live' => 'assertive', 'style' => 'padding: 0px;'));
                $output .= html_writer::end_tag('div');
                $output .= html_writer::end_tag('form');
            } else {
                // In auto mode, the icon is just an image.
                $completionpixicon = new pix_icon('i/completion-'.$completionicon, $imgalt, '',
                    array('title' => $imgalt, 'style' => 'margin: 0px;'));
                $output .= html_writer::tag('span', $this->renderPixIcon($completionpixicon),
                    array('class' => 'autocompletion'));
            }
        }
        return $output;

    }

    protected function renderPixIcon(pix_icon $obj){
        global $OUTPUT;

        $template = $obj->export_for_template($OUTPUT);

        $attrs = array();
        foreach($template['attributes'] as $item){
            $attrs[] = sprintf("%s='%s'", $item['name'], $item['value']);
        }

        return sprintf("<img %s/>", implode(" ", $attrs));
    }
}
