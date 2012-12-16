<?php
// This file is part of Stack - http://stack.bham.ac.uk/
//
// Stack is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Stack is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Stack.  If not, see <http://www.gnu.org/licenses/>.

/**
 * This file defines the editing form for editing question tests.
 *
 * @copyright 2012 the Open University
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');


/**
 * The editing form for editing question tests.
 *
 * @copyright 2012 the Open University
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class qtype_stack_question_test_form extends moodleform {
    protected function definition() {

        $mform = $this->_form;
        $question = $this->_customdata['question'];

        // Inputs.
        $mform->addElement('header', 'inputsheader', stack_string('testinputs'));

        foreach ($question->inputs as $inputname => $input) {
            // We do not require these to be filled in, (or contain valid input), as the teacher may want to test such cases.
            $input->add_to_moodleform_testinput($mform);
        }

        $mform->addElement('submit', 'complete', stack_string('completetestcase'));
        $mform->registerNoSubmitButton('complete');

        // Expected outcome.
        $mform->addElement('header', 'prtsheader', stack_string('expectedoutcomes'));

        foreach ($question->prts as $prtname => $prt) {
            $elements = array(
                $mform->createElement('text', $prtname . 'score',
                    stack_string('score'), array('size' => 2)),
                $mform->createElement('text', $prtname . 'penalty',
                    stack_string('penalty'), array('size' => 2)),
                $mform->createElement('select', $prtname . 'answernote',
                    stack_string('answernote'), $prt->get_all_answer_notes())
            );
            $mform->addGroup($elements, $prtname . 'group', $prtname, ' ', false);
        }

        // Submit buttons.
        $this->add_action_buttons(true, $this->_customdata['submitlabel']);
    }

    public function definition_after_data() {
        if ($this->_form->exportValue('complete')) {
            $this->complete_passing_testcase();
        }
    }

    protected function complete_passing_testcase() {

        $mform = $this->_form;
        $question = $this->_customdata['question'];

        $inputs = array();
        foreach ($question->inputs as $inputname => $input) {
            $inputs[$inputname] = $mform->exportValue($inputname);
        }

        $response = stack_question_test::compute_response($question, $inputs);

        foreach ($question->prts as $prtname => $prt) {
            $result = $question->get_prt_result($prtname, $response, false);
            $mform->getElement($prtname . 'group')->setValue(array(
                    $prtname . 'score'      => $result->score,
                    $prtname . 'penalty'    => $result->penalty,
                    $prtname . 'answernote' => $result->answernotes));
        }
    }

    public function validation($data, $files) {
        $errors = parent::validation($data, $files);

        return $errors;
    }
}