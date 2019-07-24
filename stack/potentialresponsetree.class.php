<?php
// This file is part of Stack - http://stack.maths.ed.ac.uk/
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

defined('MOODLE_INTERNAL') || die();

// Deals with whole potential response trees.
//
// @copyright  2012 University of Birmingham.
// @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later.

require_once(__DIR__ . '/potentialresponsenode.class.php');
require_once(__DIR__ . '/potentialresponsetreestate.class.php');

class stack_potentialresponse_tree {

    /** @var string Name of the PRT. */
    private $name;

    /** @var string Description of the PRT. */
    private $description;

    /** @var boolean Should this PRT simplify when its arguments are evaluated? */
    private $simplify;

    /** @var float total amount of fraction available from this PRT. */
    private $value;

    /** @var stack_cas_cassession Feeback variables. */
    private $feedbackvariables;

    /** @var string index of the first node. */
    private $firstnode;

    /** @var array of stack_potentialresponse_node. */
    private $nodes;

    public function __construct($name, $description, $simplify, $value, $feedbackvariables, $nodes, $firstnode) {

        $this->name        = $name;
        $this->description = $description;

        if (!is_bool($simplify)) {
            throw new stack_exception('stack_potentialresponse_tree: __construct: simplify must be a boolean.');
        } else {
            $this->simplify = $simplify;
        }

        $this->value = $value;

        if (is_a($feedbackvariables, 'stack_cas_session2') || null === $feedbackvariables) {
            $this->feedbackvariables = $feedbackvariables;
            if ($this->feedbackvariables === null) {
                // Using an empty session here makes life so much more simpler.
                $this->feedbackvariables = new stack_cas_session2(array());
            }
        } else {
            throw new stack_exception('stack_potentialresponse_tree: __construct: ' .
                    'expects $feedbackvariables to be null or a stack_cas_session.');
        }

        if ($nodes === null) {
            $nodes = array();
        }
        if (!is_array($nodes)) {
            throw new stack_exception('stack_potentialresponse_tree: __construct: ' .
                    'attempting to construct a potential response tree with potential ' .
                    'responses which are not an array of stack_potentialresponse');
        }
        foreach ($nodes as $node) {
            if (!is_a($node, 'stack_potentialresponse_node')) {
                throw new stack_exception ('stack_potentialresponse_tree: __construct: ' .
                        'attempting to construct a potential response tree with potential ' .
                        'responses which are not stack_potentialresponse');
            }
        }
        $this->nodes = $nodes;

        if (!array_key_exists($firstnode, $this->nodes)) {
            throw new stack_exception ('stack_potentialresponse_tree: __construct: ' .
                    'the specified first node does not exist in the tree.');
        }
        $this->firstnode = $firstnode;
    }

    /**
     * Create the CAS context in which we will evaluate this PRT. This contains
     * all the question variables, student responses, feedback variables, and all
     * the sans, tans and atoptions expressions from all the nodes.
     *
     * @param stack_cas_session $questionvars the question varaibles.
     * @param stack_options $options
     * @param array $answers name => value the student response.
     * @param int $seed the random number seed.
     * @return stack_cas_session initialised with all the expressions this PRT will need.
     */
    protected function create_cas_context_for_evaluation($questionvars, $options, $answers, $seed) {

        // Start with the question variables (note that order matters here).
        // TODO: this clone needs to go, we need a way of pulling the setting and seed
        // from the questionvars to start up this thing.
        $cascontext = clone $questionvars;
        // Set the value of simp from this point onwards.
        // If the question has simp:true, but the prt simp:false, then this needs to be done here.
        if ($this->simplify) {
            $simp = 'true';
        } else {
            $simp = 'false';
        }
        $cs = stack_ast_container::make_from_teacher_source('simp:'.$simp, '', new stack_cas_security(), array());

        $cascontext->add_statement($cs);
        // Add the student's responses, but only those needed by this prt.
        // Some irrelevant but invalid answers might break the CAS connection.
        foreach ($this->get_required_variables(array_keys($answers)) as $name) {
            if (array_key_exists($name . '_val', $answers)) {
                $ans = $answers[$name . '_val'];
            } else {
                $ans = $answers[$name];
            }
            // Validating as teacher at this stage removes the problem of "allowWords" which
            // we don't have access to.  This effectively allows any words here.  But the
            // student's answer has already been through validation.
            $cs = stack_ast_container::make_from_teacher_source($name . ':' . $ans, '',
                    new stack_cas_security(), array());
            // That all said, we then need to manually add in nouns to ensure these are protected.
            $cs->set_nounify(true);
            $cascontext->add_statement($cs);
        }

        // Add the feedback variables.
        $this->feedbackvariables->append_to_session($cascontext);

        // Add all the expressions from all the nodes.
        // Note this approach does not allow for effective guard clauses in the PRT.
        // All the inputs to answer tests are evaluated at the start.
        foreach ($this->nodes as $key => $node) {
            $cascontext->add_statements($node->get_context_variables($key));
        }

        if ($cascontext->get_valid()) {
            $cascontext->instantiate();
        }

        return $cascontext;
    }

    /**
     * This function actually traverses the tree and generates outcomes.
     *
     * @param stack_cas_session $questionvars the question varaibles.
     * @param stack_options $options
     * @param array $answers name => value the student response.
     * @param int $seed the random number seed.
     * @return stack_potentialresponse_tree_state the result.
     */
    public function evaluate_response(stack_cas_session2 $questionvars, $options, $answers, $seed) {

        if (empty($this->nodes)) {
            throw new stack_exception('stack_potentialresponse_tree: evaluate_response ' .
                    'attempting to traverse an empty tree. Something is wrong here.');
        }

        $localoptions = clone $options;
        $localoptions->set_option('simplify', $this->simplify);

        $cascontext = $this->create_cas_context_for_evaluation($questionvars, $localoptions, $answers, $seed);

        $results = new stack_potentialresponse_tree_state($this->value, true, 0, 0);
        $fv = $this->feedbackvariables;

        // Traverse the tree.
        $nodekey = $this->firstnode;
        $visitednodes = array();
        while ($nodekey != -1) {

            if (!array_key_exists($nodekey, $this->nodes)) {
                throw new stack_exception('stack_potentialresponse_tree: ' .
                        'evaluate_response: attempted to jump to a potential response ' .
                        'which does not exist in this question.  This is a question ' .
                        'authoring/validation problem.');
            }

            if (array_key_exists($nodekey, $visitednodes)) {
                $results->add_answernote('[PRT-CIRCULARITY]=' . $nodekey);
                break;
            }

            $visitednodes[$nodekey] = true;
            $nodekey = $this->nodes[$nodekey]->traverse($results, $nodekey, $cascontext, $answers, $localoptions);

            if ($results->_errors) {
                break;
            }
        }

        // Make sure these are PHP numbers.
        $results->_score = $results->_score + 0;
        $results->_penalty = $results->_penalty + 0;

        // Restrict score to be between 0 and 1.
        $results->_score = min(max($results->_score, 0), 1);

        // Take a continued fraction approximation of the score, within 5 decimal places of the original
        // This will round numbers like 0.999999 to exactly 1, 0.33333 to 1/3, etc.
        $results->_score = stack_utils::fix_to_continued_fraction($results->score, 5);

        // From a strictly logical point of view the 'score' and the 'penalty' are independent.
        // Hence, this clause belongs in the question behaviour.
        // From a practical point of view, it is confusing/off-putting when testing to see "score=1, penalty=0.1".
        // Why does this correct attempt attract a penalty?  So, this is a unilateral decision:
        // If the score is 1 there is never a penalty.
        if ($results->_score == 1) {
            $results->_penalty = 0;
        }

        if ($results->errors) {
            $results->_score = null;
            $results->_penalty = null;
        }
        $results->set_cas_context($cascontext, $seed);
        return $results;
    }

    /**
     * Take an array of input names, or equivalently response varaibles, (for
     * example sans1, a) and return those that are used by this potential response tree.
     *
     * @param array of string variable names.
     * @return array filter list of variable names. Only those variable names
     * referred to be this PRT are returned.
     */
    public function get_required_variables($variablenames) {

        $usedvariables = array();
        if ($this->feedbackvariables !== null) {
            $usedvariables = $this->feedbackvariables->get_variable_usage($usedvariables);
        }
        foreach ($this->nodes as $node) {
            $usedvariables = $node->get_variable_usage($usedvariables);
        }

        $requirednames = array();
        foreach ($variablenames as $name) {
            if (isset($usedvariables['read']) && isset($usedvariables['read'][$name])) {
                $requirednames[] = $name;
            }
        }
        return $requirednames;
    }

    /**
     * Looks for occurances of $variable in $string as whole words only.
     * @param string $variable a variable name.
     * @param string $string a cas string.
     * @return bool whether the string refers to the variable.
     */
    private function string_contains_variable($variable, $string) {
        $regex = '~\b' . preg_quote(strtolower($variable), '~') . '\b~';
        return preg_match($regex, strtolower($string));
    }

    /**
     * This lists all possible answer notes, used for question testing.
     * @return array string Of all the answer notes this tree might produce.
     */
    public function get_all_answer_notes() {
        $nodenotes = array();
        foreach ($this->nodes as $node) {
            $nodenotes = array_merge($nodenotes, $node->get_answer_notes());
        }
        $notes = array('NULL' => 'NULL');
        foreach ($nodenotes as $note) {
            $notes[$note] = $note;
        }
        return $notes;
    }

    /**
     * @return array with keys the same as $this->nodes, and values objects with
     *      fields falsenote, falsescore, truenote, truescore.
     */
    public function get_nodes_summary() {
        $nodesummary = array();
        foreach ($this->nodes as $key => $node) {
            $nodesummary[$key] = $node->summarise_branches();
        }
        return $nodesummary;
    }

    /**
     * @return string the name of this PRT.
     */
    public function get_name() {
        return $this->name;
    }

    /**
     * @return float the value of this PRT within the question.
     */
    public function get_value() {
        return $this->value;
    }

    /**
     * @return string Representation of the PRT for Maxima offline use.
     */
    public function get_maxima_representation() {
        $prttrace = array();
        $prttrace[] = "\n/* ". $this->name . " */";
        $fv = $this->feedbackvariables;
        if ($fv !== null) {
            $prttrace[] = $fv->get_keyval_representation();
        }
        foreach ($this->nodes as $key => $node) {
            $prttrace[] = $node->get_maxima_representation();
        }
        return implode("\n", $prttrace);
    }

    /**
     * @return string Representation of the PRT for Maxima offline use.
     */
    public function get_feedbackvariables_keyvals() {
        if (null === $this->feedbackvariables) {
            return '';
        }
        return $this->feedbackvariables->get_keyval_representation();
    }

    /**
     * @return boolean whether this PRT contains any tests that use units.
     */
    public function has_units(): bool {
        foreach ($this->nodes as $node) {
            if (strpos($node->get_test(), 'Units') === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return array Returns the answer tests used by this PRT.
     */
    public function get_answertests(): array {
        $tests = array();
        foreach ($this->nodes as $node) {
            $tests[$node->get_test()] = true;
        }
        return $tests;
    }
}
