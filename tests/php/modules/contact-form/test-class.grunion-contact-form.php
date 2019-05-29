<?php
require_jetpack_file( 'modules/contact-form/grunion-contact-form.php' );

class WP_Test_Grunion_Contact_Form extends WP_UnitTestCase {

	public static function setUpBeforeClass() {
		parent::setUpBeforeClass();
		define( 'DOING_AJAX', true ); // Defined so that 'exit' is not called in process_submission

		// Remove any relevant filters that might exist before running the tests
		remove_all_filters( 'grunion_still_email_spam' );
		remove_all_filters( 'jetpack_contact_form_is_spam' );
		remove_all_filters( 'wp_mail' );
	}

	/**
	 * Inserts globals needed to process contact form submits
	 */
	private function set_globals() {
		$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
		$_SERVER['HTTP_USER_AGENT'] = 'unit-test';
		$_SERVER['HTTP_REFERER'] = 'test';
	}

	public function setUp() {
		parent::setUp();

		$this->set_globals();

		$author_id = $this->factory->user->create( array(
			'user_email' => 'john@example.com'
		) );

		$post_id = $this->factory->post->create( array(
			'post_status' => 'draft',
			'post_author' => strval( $author_id )
		) );

		global $post;
		$post = get_post( $post_id );

		// Place post_id to contact form id to make the form processable
		$_POST['contact-form-id'] = $post_id;

		// Make the global post (used by contact forms) accessbile to tests
		$this->post = $post;

		// Initialize plugin
		$this->plugin = Grunion_Contact_Form_Plugin::init();
		// Call to add tokenization hook
		$this->plugin->process_form_submission();
	}

	public function tearDown() {
		parent::tearDown();

		// Remove filters after running tests
		remove_all_filters( 'wp_mail' );
		remove_all_filters( 'grunion_still_email_spam' );
		remove_all_filters( 'jetpack_contact_form_is_spam' );
	}

	private function add_field_values( $values ) {
		foreach( $values as $key => $val ) {
			$_POST['g' . $this->post->ID . '-' . $key] = $val;
		}
	}

	/**
	 * @author tonykova
	 * @covers Grunion_Contact_Form::process_submission
	 *
	 * Tests that the submission as a whole will produce something in the
	 * database when required information is provided
	 */
	public function test_process_submission_will_store_a_feedback_correctly_with_default_form() {
		$form = new Grunion_Contact_Form( array() );
		$result = $form->process_submission();

		// Processing should be successful and produce the success message
		$this->assertTrue( is_string( $result ) );

		$feedback = get_posts( array( 'post_type' => 'feedback' ) );
		$this->assertEquals( 1, count( $feedback ), 'There should be one feedback after process_submission' );

		// Default metadata should be saved
		$submission = $feedback[0];
		$email = get_post_meta( $submission->ID, '_feedback_email', true );
		$this->assertEquals( '"john" <john@example.com>', $email['to'][0] );
		$this->assertContains( 'IP Address: 127.0.0.1', $email['message'] );
	}

	/**
	 * @author tonykova
	 * @covers Grunion_Contact_Form::process_submission
	 *
	 * Tests that the submission as a whole will produce something in the
	 * database when some labels are provided
	 */
	public function test_process_submission_will_store_extra_field_metadata() {
		// Fill field values
		$this->add_field_values( array(
			'name'     => 'John Doe',
			'dropdown' => 'First option',
			'radio'    =>'Second option',
			'text'     =>'Texty text'
		) );

		// Initialize a form with name, dropdown and radiobutton (first, second
		// and third option), text field
		$form = new Grunion_Contact_Form( array(), "[contact-field label='Name' type='name' required='1'/][contact-field label='Dropdown' type='select' options='First option,Second option,Third option'/][contact-field label='Radio' type='radio' options='First option,Second option,Third option'/][contact-field label='Text' type='text'/]" );
		$result = $form->process_submission();

		// Processing should be successful and produce the success message
		$this->assertTrue( is_string( $result ) );

		$feedback = get_posts( array( 'post_type' => 'feedback' ) );
		$this->assertEquals( 1, count( $feedback ), 'There should be one feedback after process_submission' );

		// Default metadata should be saved
		$submission = $feedback[0];
		$extra_fields = get_post_meta( $submission->ID, '_feedback_extra_fields', true );

		$this->assertEquals( 3, count( $extra_fields ), 'There should be exactly three extra fields when one of the fields is name, and the others are an extra dropdown, radio button field and text field' );
		// Metadata starts counting from 5, because post content has:
		// 1_Name
		// 2_Dropdown
		// 3_Radio
		// 4_Text
		$this->assertEquals( $extra_fields['5_Dropdown'], 'First option', 'When the first option of a dropdown field with label Dropdown is selected, there should be metadata with that key and value' );
		$this->assertEquals( $extra_fields['6_Radio'], 'Second option', 'When the first option of a radio button field with label Radio is selected, there should be metadata with that key and value' );
		$this->assertEquals( $extra_fields['7_Text'], 'Texty text', 'When the text field with label Text is filled with the text \'Texty text\', there should be metadata with that key and value' );
	}

	/**
	 * @author tonykova
	 * @covers Grunion_Contact_Form::process_submission
	 *
	 * Tests that the submission will store the subject when specified
	 */
	public function test_process_submission_will_store_subject_when_specified() {
		$form = new Grunion_Contact_Form( array( 'subject' => 'I\'m sorry, but the party\'s over') ); // Default form
		$result = $form->process_submission();

		// Processing should be successful and produce the success message
		$this->assertTrue( is_string( $result ) );

		$feedback = get_posts( array( 'post_type' => 'feedback' ) );
		$this->assertEquals( 1, count( $feedback ), 'There should be one feedback after process_submission' );

		// Default metadata should be saved
		$submission = $feedback[0];

		$this->assertContains( 'SUBJECT: I\'m sorry, but the party\'s over', $submission->post_content, 'The stored subject didn\'t match the given' );
	}

	/**
	 * @author tonykova
	 * @covers Grunion_Contact_Form::process_submission
	 */
	public function test_process_submission_will_store_subject_with_token_replaced_from_name_and_text_field() {
		// Fill field values
		$this->add_field_values( array(
			'name'     => 'John Doe',
			'state'     =>'Kansas'
		) );

		$form = new Grunion_Contact_Form( array( 'subject' => 'Hello {name} from {state}!'), "[contact-field label='Name' type='name' required='1'/][contact-field label='State' type='text'/]" );

		$result = $form->process_submission();

		// Processing should be successful and produce the success message
		$this->assertTrue( is_string( $result ) );

		$feedback = get_posts( array( 'post_type' => 'feedback' ) );
		$this->assertEquals( 1, count( $feedback ), 'There should be one feedback after process_submission' );

		// Default metadata should be saved
		$submission = $feedback[0];

		$this->assertContains( 'SUBJECT: Hello John Doe from Kansas!', $submission->post_content, 'The stored subject didn\'t match the given' );
	}

	/**
	 * @author tonykova
	 * @covers Grunion_Contact_Form::process_submission
	 */
	public function test_process_submission_will_store_subject_with_token_replaced_from_radio_button_field() {
		// Fill field values
		$this->add_field_values( array(
			'name'     => 'John Doe',
			'state'     =>'Kansas'
		) );

		$form = new Grunion_Contact_Form( array( 'subject' => 'Hello {name} from {state}!'), "[contact-field label='Name' type='name' required='1'/][contact-field label='State' type='radio' options='Kansas,California'/]" );
		$result = $form->process_submission();

		// Processing should be successful and produce the success message
		$this->assertTrue( is_string( $result ) );

		$feedback = get_posts( array( 'post_type' => 'feedback' ) );
		$this->assertEquals( 1, count( $feedback ), 'There should be one feedback after process_submission' );

		// Default metadata should be saved
		$submission = $feedback[0];

		$this->assertContains( 'SUBJECT: Hello John Doe from Kansas!', $submission->post_content, 'The stored subject didn\'t match the given' );
	}

	/**
	 * @author tonykova
	 * @covers Grunion_Contact_Form::process_submission
	 */
	public function test_process_submission_will_store_subject_with_token_replaced_from_dropdown_field() {
		// Fill field values
		$this->add_field_values( array(
			'name'     => 'John Doe',
			'state'     =>'Kansas'
		) );

		$form = new Grunion_Contact_Form( array( 'subject' => 'Hello {name} from {state}!'), "[contact-field label='Name' type='name' required='1'/][contact-field label='State' type='select' options='Kansas,California'/]" );
		$result = $form->process_submission();

		// Processing should be successful and produce the success message
		$this->assertTrue( is_string( $result ) );

		$feedback = get_posts( array( 'post_type' => 'feedback' ) );
		$this->assertEquals( 1, count( $feedback ), 'There should be one feedback after process_submission' );

		// Default metadata should be saved
		$submission = $feedback[0];

		$this->assertContains( 'SUBJECT: Hello John Doe from Kansas!', $submission->post_content, 'The stored subject didn\'t match the given' );
	}

	/**
	 * @author tonykova
	 * @covers Grunion_Contact_Form::process_submission
	 */
	public function test_process_submission_will_store_fields_and_their_values_to_post_content() {
		// Fill field values
		$this->add_field_values( array(
			'name'     => 'John Doe',
			'dropdown' => 'First option',
			'radio'    => 'Second option',
			'text'     => 'Texty text'
		) );

		// Initialize a form with name, dropdown and radiobutton (first, second
		// and third option), text field
		$form = new Grunion_Contact_Form( array(), "[contact-field label='Name' type='name' required='1'/][contact-field label='Dropdown' type='select' options='First option,Second option,Third option'/][contact-field label='Radio' type='radio' options='First option,Second option,Third option'/][contact-field label='Text' type='text'/]" );
		$result = $form->process_submission();

		// Processing should be successful and produce the success message
		$this->assertTrue( is_string( $result ) );

		$feedback = get_posts( array( 'post_type' => 'feedback' ) );
		$this->assertEquals( 1, count( $feedback ), 'There should be one feedback after process_submission' );

		// Default metadata should be saved
		$submission = $feedback[0];

		$this->assertContains( '[1_Name] =&gt; John Doe', $submission->post_content, 'Post content did not contain the name label and/or value' );
		$this->assertContains( '[2_Dropdown] =&gt; First option', $submission->post_content, 'Post content did not contain the dropdown label and/or value' );
		$this->assertContains( '[3_Radio] =&gt; Second option', $submission->post_content, 'Post content did not contain the radio button label and/or value' );
		$this->assertContains( '[4_Text] =&gt; Texty text', $submission->post_content, 'Post content did not contain the text field label and/or value' );
	}

	/**
	 * @author tonykova
	 * @covers Grunion_Contact_Form::process_submission
	 */
	public function test_process_submission_will_store_fields_and_their_values_to_email_meta() {
		// Fill field values
		$this->add_field_values( array(
			'name'     => 'John Doe',
			'dropdown' => 'First option',
			'radio'    => 'Second option',
			'text'     => 'Texty text'
		) );

		// Initialize a form with name, dropdown and radiobutton (first, second
		// and third option), text field
		$form = new Grunion_Contact_Form( array(), "[contact-field label='Name' type='name' required='1'/][contact-field label='Dropdown' type='select' options='First option,Second option,Third option'/][contact-field label='Radio' type='radio' options='First option,Second option,Third option'/][contact-field label='Text' type='text'/]" );
		$result = $form->process_submission();

		// Processing should be successful and produce the success message
		$this->assertTrue( is_string( $result ) );

		$feedback = get_posts( array( 'post_type' => 'feedback' ) );
		$this->assertEquals( 1, count( $feedback ), 'There should be one feedback after process_submission' );

		// Default metadata should be saved
		$submission = $feedback[0];
		$email = get_post_meta( $submission->ID, '_feedback_email', true );

		$expected = '<b>Name:</b> John Doe<br /><br />';
		$expected .= '<b>Dropdown:</b> First option<br /><br />';
		$expected .= '<b>Radio:</b> Second option<br /><br />';
		$expected .= '<b>Text:</b> Texty text<br /><br />';

		$email_body = explode( PHP_EOL . PHP_EOL, $email['message'] );

		$email_body = $email_body[0];

		$this->assertEquals( 0, strpos( $expected, $email_body ) );
	}

	/**
	 * @author tonykova
	 * @covers Grunion_Contact_Form::process_submission
	 */
	public function test_process_submission_sends_correct_single_email() {
		// Fill field values
		$this->add_field_values( array(
			'name'     => 'John Doe',
			'dropdown' => 'First option',
			'radio'    => 'Second option',
			'text'     => 'Texty text'
		) );

		add_filter( 'wp_mail', array( $this, 'pre_test_process_submission_sends_correct_single_email' ) );

		// Initialize a form with name, dropdown and radiobutton (first, second
		// and third option), text field
		$form = new Grunion_Contact_Form( array( 'to' => '"john" <john@example.com>', 'subject' => 'Hello there!' ), "[contact-field label='Name' type='name' required='1'/][contact-field label='Dropdown' type='select' options='First option,Second option,Third option'/][contact-field label='Radio' type='radio' options='First option,Second option,Third option'/][contact-field label='Text' type='text'/]" );
		$result = $form->process_submission();
		$this->assertNotNull( $result );
	}

	public function pre_test_process_submission_sends_correct_single_email( $args ){
		$this->assertContains( '"john" <john@example.com>', $args['to'] );
		$this->assertEquals( 'Hello there!', $args['subject'] );

		$expected = '<b>Name:</b> John Doe<br /><br />';
		$expected .= '<b>Dropdown:</b> First option<br /><br />';
		$expected .= '<b>Radio:</b> Second option<br /><br />';
		$expected .= '<b>Text:</b> Texty text<br /><br />';

		// Divides email by the first empty line
		$email_body = explode( PHP_EOL . PHP_EOL, $args['message'] );
		$email_body = $email_body[0];

		$this->assertEquals( 0, strpos( $expected, $email_body ) );
	}

	/**
	 * @author tonykova
	 * @covers Grunion_Contact_Form::process_submission
	 */
	public function test_process_submission_sends_correct_multiple_email() {
		// Fill field values
		$this->add_field_values( array(
			'name'     => 'John Doe',
			'dropdown' => 'First option',
			'radio'    => 'Second option',
			'text'     => 'Texty text'
		) );

		add_filter( 'wp_mail', array( $this, 'pre_test_process_submission_sends_correct_multiple_email' ) );

		// Initialize a form with name, dropdown and radiobutton (first, second
		// and third option), text field
		$form = new Grunion_Contact_Form( array( 'to' => 'john@example.com, jane@example.com', 'subject' => 'Hello there!' ), "[contact-field label='Name' type='name' required='1'/][contact-field label='Dropdown' type='select' options='First option,Second option,Third option'/][contact-field label='Radio' type='radio' options='First option,Second option,Third option'/][contact-field label='Text' type='text'/]" );
		$result = $form->process_submission();
		$this->assertNotNull( $result );
	}

	public function pre_test_process_submission_sends_correct_multiple_email( $args ){
		$this->assertEquals( array( '"john" <john@example.com>','"jane" <jane@example.com>'), $args['to'] );
	}

	/**
	 * @author tonykova
	 * @covers Grunion_Contact_Form::process_submission
	 */
	public function test_process_submission_fails_if_spam_marked_with_WP_Error() {
		add_filter( 'jetpack_contact_form_is_spam', array( $this, 'pre_test_process_submission_fails_if_spam_marked_with_WP_Error' ), 11 ); // Run after akismet filter

		$form = new Grunion_Contact_Form( array() );
		$result = $form->process_submission();

		$this->assertInstanceOf( 'WP_Error', $result, 'When $is_spam contains a WP_Error, the result of process_submission should be a WP_Error' );
		$this->assertEquals( 'Message is spam', $result->get_error_message() );
	}

	public function pre_test_process_submission_fails_if_spam_marked_with_WP_Error(){
		return new WP_Error( 'spam', 'Message is spam' );
	}

	/**
	 * @author tonykova
	 * @covers Grunion_Contact_Form::process_submission
	 */
	public function test_process_submission_wont_send_spam_if_marked_as_spam_with_true() {
		add_filter( 'jetpack_contact_form_is_spam', '__return_true', 11 ); // Run after akismet filter

		add_filter( 'wp_mail', array( $this, 'pre_test_process_submission_wont_send_spam_if_marked_as_spam_with_true' ) );

		$form = new Grunion_Contact_Form( array( 'to' => 'john@example.com' ) );
		$result = $form->process_submission();
		$this->assertNotNull( $result );
	}

	public function pre_test_process_submission_wont_send_spam_if_marked_as_spam_with_true(){
		$this->assertTrue( false ); // Fail if trying to send
	}

	/**
	 * @author tonykova
	 * @covers Grunion_Contact_Form::process_submission
	 */
	public function test_process_submission_labels_message_as_spam_in_subject_if_marked_as_spam_with_true_and_sending_spam() {
		add_filter( 'jetpack_contact_form_is_spam', '__return_true' , 11 ); // Run after akismet filter

		add_filter( 'grunion_still_email_spam', '__return_true' );

		add_filter( 'wp_mail', array( $this, 'pre_test_process_submission_labels_message_as_spam_in_subject_if_marked_as_spam_with_true_and_sending_spam') );

		$form = new Grunion_Contact_Form( array( 'to' => 'john@example.com' ) );
		$result = $form->process_submission();
		$this->assertNotNull( $result );
	}

	public function pre_test_process_submission_labels_message_as_spam_in_subject_if_marked_as_spam_with_true_and_sending_spam( $args ){
		$this->assertContains( '***SPAM***', $args['subject'] );
	}


	/**
	 * @author tonykova
	 * @covers ::grunion_delete_old_spam
	 */
	public function test_grunion_delete_old_spam_deletes_an_old_post_marked_as_spam() {
		$post_id = $this->factory->post->create( array(
			'post_type'     => 'feedback',
			'post_status'   => 'spam',
			'post_date_gmt' => '1987-01-01 12:00:00'
		) );

		grunion_delete_old_spam();
		$this->assertEquals( null, get_post( $post_id ), 'An old spam feedback should be deleted' );
	}

	/**
	 * @author tonykova
	 * @covers ::grunion_delete_old_spam
	 */
	public function test_grunion_delete_old_spam_does_not_delete_a_new_post_marked_as_spam() {
		$post_id = $this->factory->post->create( array(
			'post_type'     => 'feedback',
			'post_status'   => 'spam'
		) );

		grunion_delete_old_spam();
		$this->assertEquals( $post_id, get_post( $post_id )->ID, 'A new spam feedback should be left intact when deleting old spam' );
	}

	/**
	 * @author tonykova
	 * @covers Grunion_Contact_Form_Plugin::replace_tokens_with_input
	 */
	public function test_token_left_intact_when_no_matching_field() {
		$plugin = Grunion_Contact_Form_Plugin::init();
		$subject = 'Hello {name}!';
		$field_values = array(
			'City' => 'Chicago'
		);

		$this->assertEquals( 'Hello {name}!', $plugin->replace_tokens_with_input( $subject, $field_values ) );
	}

	/**
	 * @author tonykova
	 * @covers Grunion_Contact_Form_Plugin::replace_tokens_with_input
	 */
	public function test_replaced_with_empty_string_when_no_value_in_field() {
		$plugin = Grunion_Contact_Form_Plugin::init();
		$subject = 'Hello {name}!';
		$field_values = array(
			'Name' => null
		);

		$this->assertEquals( 'Hello !', $plugin->replace_tokens_with_input( $subject, $field_values ) );
	}

	/**
	 * @author tonykova
	 * @covers Grunion_Contact_Form_Plugin::replace_tokens_with_input
	 */
	public function test_token_can_replace_entire_subject_with_token_field_whose_name_has_whitespace() {
		$plugin = Grunion_Contact_Form_Plugin::init();
		$subject = '{subject token}';
		$field_values = array(
			'Subject Token' => 'Chicago'
		);

		$this->assertEquals( 'Chicago', $plugin->replace_tokens_with_input( $subject, $field_values ) );
	}

	/**
	 * @author tonykova
	 * @covers Grunion_Contact_Form_Plugin::replace_tokens_with_input
	 */
	public function test_token_with_curly_brackets_can_be_replaced() {
		$plugin = Grunion_Contact_Form_Plugin::init();
		$subject = '{subject {token}}';
		$field_values = array(
			'Subject {Token}' => 'Chicago'
		);

		$this->assertEquals( 'Chicago', $plugin->replace_tokens_with_input( $subject, $field_values ) );
	}

	/**
	 * @author tonykova
	 * @covers Grunion_Contact_Form::parse_contact_field
	 */
	public function test_parse_contact_field_keeps_string_unchaned_when_no_escaping_necesssary() {
		add_shortcode( 'contact-field', array( 'Grunion_Contact_Form', 'parse_contact_field' ) );

		$shortcode = "[contact-field label=\"Name\" type=\"name\" required=\"1\"/][contact-field label=\"Email\" type=\"email\" required=\"1\"/][contact-field label=\"asdasd\" type=\"text\"/][contact-field id=\"1\" required derp herp asd lkj]adsasd[/contact-field]";
		$html = do_shortcode( $shortcode );

		$this->assertEquals( $shortcode, $html );
	}

	public function test_make_sure_that_we_add_defatul_lable_when_non_is_present() {
		add_shortcode( 'contact-field', array( 'Grunion_Contact_Form', 'parse_contact_field' ) );
		$shortcode = "[contact-field type='name' required='1' /]";
		$html = do_shortcode( $shortcode );
		$this->assertEquals( "[contact-field type=\"name\" required=\"1\" label=\"Name\"/]", $html );
	}

	public function test_make_sure_that_we_remove_empty_options_from_form_field() {
		add_shortcode( 'contact-field', array( 'Grunion_Contact_Form', 'parse_contact_field' ) );
		$shortcode = "[contact-field type='select' required='1' options='fun,,run' label='fun times' values='go,,have some fun'/]";
		$html = do_shortcode( $shortcode );
		$this->assertEquals( "[contact-field type=\"select\" required=\"1\" options=\"fun,run\" label=\"fun times\" values=\"go,have some fun\"/]", $html );
	}

	public function test_make_sure_text_field_renders_as_expected() {
		$attributes = array(
			'label' => 'fun',
			'type' => 'text',
			'class' => 'lalala',
			'default' => 'foo',
			'placeholder' => 'PLACEHOLDTHIS!',
			'id' => 'funID'
		);

		$expected_attributes = array_merge( $attributes, array( 'input_type' => 'text' ) );
		$this->assertValidField( $this->render_field( $attributes ), $expected_attributes );
	}
	public function test_make_sure_email_field_renders_as_expected() {
		$attributes = array(
			'label' => 'fun',
			'type' => 'email',
			'class' => 'lalala',
			'default' => 'foo',
			'placeholder' => 'PLACEHOLDTHIS!',
			'id' => 'funID'
		);

		$expected_attributes = array_merge( $attributes, array( 'input_type' => 'email' ) );
		$this->assertValidField( $this->render_field( $attributes ), $expected_attributes );
	}

	public function test_make_sure_url_field_renders_as_expected() {
		$attributes = array(
			'label' => 'fun',
			'type' => 'url',
			'class' => 'lalala',
			'default' => 'foo',
			'placeholder' => 'PLACEHOLDTHIS!',
			'id' => 'funID'
		);

		$expected_attributes = array_merge( $attributes, array( 'input_type' => 'url' ) );
		$this->assertValidField( $this->render_field( $attributes ), $expected_attributes );
	}

	public function test_make_sure_telephone_field_renders_as_expected() {
		$attributes = array(
			'label' => 'fun',
			'type' => 'telephone',
			'class' => 'lalala',
			'default' => 'foo',
			'placeholder' => 'PLACEHOLDTHIS!',
			'id' => 'funID'
		);

		$expected_attributes = array_merge( $attributes, array( 'input_type' => 'tel' ) );
		$this->assertValidField( $this->render_field( $attributes ), $expected_attributes );
	}

	public function test_make_sure_date_field_renders_as_expected() {
		$attributes = array(
			'label' => 'fun',
			'type' => 'date',
			'class' => 'lalala',
			'default' => 'foo',
			'placeholder' => 'PLACEHOLDTHIS!',
			'id' => 'funID'
		);

		$expected_attributes = array_merge( $attributes, array( 'input_type' => 'text' ) );
		$this->assertValidField( $this->render_field( $attributes ), $expected_attributes );
	}

	public function test_make_sure_textarea_field_renders_as_expected() {
		$attributes = array(
			'label' => 'fun',
			'type' => 'textarea',
			'class' => 'lalala',
			'default' => 'foo',
			'placeholder' => 'PLACEHOLDTHIS!',
			'id' => 'funID'
		);

		$expected_attributes = array_merge( $attributes, array( 'input_type' => 'textarea' ) );
		$this->assertValidField( $this->render_field( $attributes ), $expected_attributes );
	}

	public function test_make_sure_checkbox_field_renders_as_expected() {
		$attributes = array(
			'label' => 'fun',
			'type' => 'checkbox',
			'class' => 'lalala',
			'default' => 'foo',
			'placeholder' => 'PLACEHOLDTHIS!',
			'id' => 'funID'
		);

		$expected_attributes = array_merge( $attributes, array( 'input_type' => 'checkbox' ) );
		$this->assertValidCheckboxField( $this->render_field( $attributes ), $expected_attributes );
	}
	// Multiple fields
	public function test_make_sure_checkbox_multiple_field_renders_as_expected() {
		$attributes = array(
			'label' => 'fun',
			'type' => 'checkbox-multiple',
			'class' => 'lalala',
			'default' => 'option 1',
			'id' => 'funID',
			'options' => array( 'option 1', 'option 2' ),
			'values' => array( 'option 1', 'option 2' ),
		);

		$expected_attributes = array_merge( $attributes, array( 'input_type' => 'checkbox' ) );
		$this->assertValidFieldMultiField( $this->render_field( $attributes ), $expected_attributes );
	}

	public function test_make_sure_radio_field_renders_as_expected() {
		$attributes = array(
			'label' => 'fun',
			'type' => 'radio',
			'class' => 'lalala',
			'default' => 'option 1',
			'id' => 'funID',
			'options' => array( 'option 1', 'option 2' ),
			'values' => array( 'option 1', 'option 2' ),
		);

		$expected_attributes = array_merge( $attributes, array( 'input_type' => 'radio' ) );
		$this->assertValidFieldMultiField( $this->render_field( $attributes ), $expected_attributes );
	}

	public function test_make_sure_select_field_renders_as_expected() {
		$attributes = array(
			'label' => 'fun',
			'type' => 'select',
			'class' => 'lalala',
			'default' => 'option 1',
			'id' => 'funID',
			'options' => array( 'option 1', 'option 2' ),
			'values' => array( 'o1', 'o2' ),
		);

		$expected_attributes = array_merge( $attributes, array( 'input_type' => 'select' ) );
		$this->assertValidFieldMultiField( $this->render_field( $attributes ), $expected_attributes );
	}

	public function render_field( $attributes ) {
		$form = new Grunion_Contact_Form( array() );
		$field = new Grunion_Contact_Form_Field( $attributes, '', $form );
		return $field->render();
	}

	public function getCommonDiv( $html ) {

		$doc = new DOMDocument();
		$doc->loadHTML( $html );
		return $this->getFirstElement( $doc, 'div' );
	}

	public function getFirstElement( $dom, $tag, $index = 0) {
		$elements = $dom->getElementsByTagName( $tag );
		if ( ! is_array( $elements ) ) {
			return $elements->item( $index );
		}
		return $elements[ $index ];
	}

	public function assertCommonValidHtml( $wrapperDiv, $attributes ) {

		if ( $attributes['type'] === 'date' ) {
			$attributes['class'] = 'jp-contact-form-date';
		}
		$this->assertEquals(
			$wrapperDiv->getAttribute( 'class' ),
			"grunion-field-wrap grunion-field-{$attributes['type']}-wrap {$attributes['class']}-wrap",
			'div class attribute doesn\'t match'
		);

		// Get label
		$label = $this->getFirstElement( $wrapperDiv, 'label' );

		$this->assertEquals( trim( $label->nodeValue), $attributes['label'], 'Label is not what we expect it to be...' );
	}

	public function assertValidField( $html, $attributes ) {

		$wrapperDiv = $this->getCommonDiv( $html );
		$this->assertCommonValidHtml( $wrapperDiv, $attributes );

		// Get label
		$label = $this->getFirstElement( $wrapperDiv, 'label' );

		//Input
		$input =  (
			$attributes['type'] === 'textarea'
			? $this->getFirstElement( $wrapperDiv, 'textarea' )
			: $this->getFirstElement( $wrapperDiv, 'input' )
		);

		// label matches for matches input ID
		$this->assertEquals(
			$label->getAttribute( 'for' ),
			$input->getAttribute( 'id' ),
			'label for does not equal input ID!'
		);

		$this->assertEquals( $input->getAttribute( 'placeholder' ), $attributes['placeholder'], 'Placeholder doesn\'t match' );
		if ( $attributes['type'] === 'textarea' ) {
			$this->assertEquals( $input->nodeValue, $attributes['default'], 'value and default doesn\'t match' );
			$this->assertEquals(
				$label->getAttribute( 'for' ),
				'contact-form-comment-' . $input->getAttribute( 'name' )
				, 'label for doesn\'t match the input name'
			);
		} else {
			$this->assertEquals( $input->getAttribute( 'type' ), $attributes['input_type'], 'Type doesn\'t match' );
			$this->assertEquals( $input->getAttribute( 'value' ), $attributes['default'], 'value and default doesn\'t match' );
			// label matches for matches input name
			$this->assertEquals(
				$label->getAttribute( 'for' ),
				$input->getAttribute( 'name' )
				, 'label for doesn\'t match the input name'
			);
		}

		if ( $attributes['type'] === 'date' ) {
			$this->assertEquals(
				$input->getAttribute( 'class' ),
				"{$attributes['type']} jp-contact-form-date",
				'input class attribute doesn\'t match'
			);
		} else {
			$this->assertEquals(
				$input->getAttribute( 'class' ),
				"{$attributes['type']} {$attributes['class']}",
				'input class attribute doesn\'t match'
			);
		}


	}

	public function assertValidCheckboxField( $html, $attributes ) {

		$wrapperDiv = $this->getCommonDiv( $html );
		$this->assertCommonValidHtml( $wrapperDiv, $attributes );

		$label = $this->getFirstElement( $wrapperDiv, 'label' );
		$input = $this->getFirstElement( $label, 'input' );

		$this->assertEquals( $label->getAttribute( 'class' ), 'grunion-field-label '.$attributes['type'], 'label class doesn\'t match' );


		$this->assertEquals( $input->getAttribute( 'name' ), $attributes['id'], 'Input name doesn\'t match' );
		$this->assertEquals( $input->getAttribute( 'value' ), 'Yes', 'Input value doesn\'t match' );
		$this->assertEquals( $input->getAttribute( 'type' ), $attributes['type'], 'Input type doesn\'t match' );
		if ( $attributes['default'] ) {
			$this->assertEquals( $input->getAttribute( 'checked' ), 'checked', 'Input checked doesn\'t match' );
		}

		$this->assertEquals( $input->getAttribute( 'class' ), $attributes['type'] . ' ' .$attributes['class'], 'Input class doesn\'t match' );
	}

	public function assertValidFieldMultiField( $html, $attributes ) {

		$wrapperDiv = $this->getCommonDiv( $html );
		$this->assertCommonValidHtml( $wrapperDiv, $attributes );

		// Get label
		$label = $this->getFirstElement( $wrapperDiv, 'label' );

		//Inputs
		if ( $attributes['type'] === 'select' ) {
			$this->assertEquals( $label->getAttribute( 'class' ), 'grunion-field-label select', 'label class doesn\'t match' );

			$select = $this->getFirstElement( $wrapperDiv, 'select' );
			$this->assertEquals(
				$label->getAttribute( 'for' ),
				$select->getAttribute( 'id' ),
				'label for does not equal input ID!'
			);

			$this->assertEquals(
				$label->getAttribute( 'for' ),
				$select->getAttribute( 'name' ),
				'label for does not equal input name!'
			);

			$this->assertEquals( $select->getAttribute( 'class' ), 'select '. $attributes['class'], ' select class does not match expected' );

			// First Option
			$option = $this->getFirstElement( $select, 'option' );
			$this->assertEquals( $option->getAttribute( 'value' ), $attributes['values'][0], 'Input value doesn\'t match' );
			$this->assertEquals( $option->getAttribute( 'selected' ), 'selected', 'Input is not selected' );
			$this->assertEquals( $option->nodeValue, $attributes['options'][0], 'Input does not match the option' );

		} else {
			$this->assertEquals( $label->getAttribute( 'class' ), 'grunion-field-label', 'label class doesn\'t match' );
			// Radio and Checkboxes
			$second_label = $this->getFirstElement( $wrapperDiv, 'label', 1 );
			$this->assertEquals( $second_label->nodeValue, ' ' . $attributes['options'][0] ); // extra space added for a padding

			$input = $this->getFirstElement( $second_label, 'input' );
			$this->assertEquals( $input->getAttribute( 'type' ), $attributes['input_type'], 'Type doesn\'t match' );
			if (  $attributes['input_type'] === 'radio' ) {
				$this->assertEquals( $input->getAttribute( 'name' ), $attributes['id'], 'Input name doesn\'t match' );
			} else {
				$this->assertEquals( $input->getAttribute( 'name' ), $attributes['id'] . '[]', 'Input name doesn\'t match' );
			}
			$this->assertEquals( $input->getAttribute( 'value' ), $attributes['values'][0], 'Input value doesn\'t match' );
			$this->assertEquals( $input->getAttribute( 'class' ), $attributes['type'] . ' '. $attributes['class'], 'Input class doesn\'t match' );
			$this->assertEquals( $input->getAttribute( 'checked' ), 'checked', 'Input checked doesn\'t match' );
		}
	}

	/**
	 * @author tonykova
	 * @covers Grunion_Contact_Form::parse_contact_field
	 */
	public function test_parse_contact_field_escapes_things_inside_a_value_and_attribute_and_the_content() {
		global $wp_version;
		add_shortcode( 'contact-field', array( 'Grunion_Contact_Form', 'parse_contact_field' ) );

		$shortcode = "[contact-field label='Name' type='name' required='1'/][contact-field label='Email' type=''email'' req'uired='1'/][contact-field label='asdasd' type='text'/][contact-field id='1' required 'derp' herp asd lkj]adsasd[/contact-field]";
		$html = do_shortcode( $shortcode );

		// The expected string has some quotes escaped, since we want to make
		// sure we don't output anything harmful

		if ( version_compare( $wp_version, '4.9-alpha', '>') ){
			$this->assertEquals( "[contact-field label=\"Name\" type=\"name\" required=\"1\"/][contact-field label=\"Email\" type=&#039;&#039;email&#039;&#039; req&#039;uired=&#039;1&#039;/][contact-field label=\"asdasd\" type=\"text\"/][contact-field id=\"1\" required derp herp asd lkj]adsasd[/contact-field]", $html );
		} else {
			$this->assertEquals( "[contact-field label=\"Name\" type=\"name\" required=\"1\"/][contact-field label=\"Email\" type=&#039;&#039;email&#039;&#039; req&#039;uired=&#039;1&#039;/][contact-field label=\"asdasd\" type=\"text\"/][contact-field id=\"1\" required &#039;derp&#039; herp asd lkj]adsasd[/contact-field]", $html );
		}
	}

	/**
	 * Test get_export_data_for_posts with fully vaid data input.
	 *
	 * @group csvexport
	 */
	public function test_get_export_data_for_posts_fully_valid_data() {
		/** @var Grunion_Contact_Form_Plugin $mock */
		$mock = $this->getMockBuilder( 'Grunion_Contact_Form_Plugin' )
		             ->setMethods( array(
			                           'get_post_meta_for_csv_export',
			                           'get_parsed_field_contents_of_post',
			                           'get_post_content_for_csv_export',
			                           'map_parsed_field_contents_of_post_to_field_names'
		                           ) )
		             ->disableOriginalConstructor()
		             ->getMock();


		$get_post_meta_for_csv_export_map = array(
			array(
				15,
				array(
					'key1' => 'value1',
					'key2' => 'value2',
					'key3' => 'value3',
					'key4' => 'value4',

				)
			),
			array(
				16,
				array(
					'key3' => 'value3',
					'key4' => 'value4',
					'key5' => 'value5',
					'key6' => 'value6'
				)
			),
		);

		$get_parsed_field_contents_of_post_map = array(
			array( 15, array( '_feedback_subject' => 'subj1' ) ),
			array( 16, array( '_feedback_subject' => 'subj2' ) ),
		);

		$get_post_content_for_csv_export_map = array(
			array( 15, 'This is my test 15' ),
			array( 16, 'This is my test 16' ),
		);

		$mapped_fields_contents_map = array(
			array(
				array( '_feedback_subject' => 'subj1', '_feedback_main_comment' => 'This is my test 15' ),
				array(
					'Contact Form' => 'subj1',
					'4_Comment'    => 'This is my test 15'
				)
			),
			array(
				array( '_feedback_subject' => 'subj2', '_feedback_main_comment' => 'This is my test 16' ),
				array(
					'Contact Form' => 'subj2',
					'4_Comment'    => 'This is my test 16'
				)
			),
		);

		$mock->expects( $this->exactly( 2 ) )
		     ->method( 'get_post_meta_for_csv_export' )
		     ->will( $this->returnValueMap( $get_post_meta_for_csv_export_map ) );


		$mock->expects( $this->exactly( 2 ) )
		     ->method( 'get_parsed_field_contents_of_post' )
		     ->will( $this->returnValueMap( $get_parsed_field_contents_of_post_map ) );

		$mock->expects( $this->exactly( 2 ) )
		     ->method( 'get_post_content_for_csv_export' )
		     ->will( $this->returnValueMap( $get_post_content_for_csv_export_map ) );

		$mock->expects( $this->exactly( 2 ) )
		     ->method( 'map_parsed_field_contents_of_post_to_field_names' )
		     ->will( $this->returnValueMap( $mapped_fields_contents_map ) );

		$result = $mock->get_export_data_for_posts( array( 15, 16 ) );

		$expected_result = array(
			'Contact Form' => array( 'subj1', 'subj2' ),
			'key1'         => array( 'value1', '' ),
			'key2'         => array( 'value2', '' ),
			'key3'         => array( 'value3', 'value3' ),
			'key4'         => array( 'value4', 'value4' ),
			'key5'         => array( '', 'value5' ),
			'key6'         => array( '', 'value6' ),
			'4_Comment'    => array( 'This is my test 15', 'This is my test 16' ),
		);

		$this->assertEquals( $expected_result, $result );
	}


	/**
	 * Test get_export_data_for_posts with single invalid entry for post meta
	 *
	 * @group csvexport
	 */
	public function test_get_export_data_for_posts_invalid_single_entry_meta() {
		/** @var Grunion_Contact_Form_Plugin $mock */
		$mock = $this->getMockBuilder( 'Grunion_Contact_Form_Plugin' )
		             ->setMethods( array(
			                           'get_post_meta_for_csv_export',
			                           'get_parsed_field_contents_of_post',
			                           'get_post_content_for_csv_export',
			                           'map_parsed_field_contents_of_post_to_field_names'
		                           ) )
		             ->disableOriginalConstructor()
		             ->getMock();


		$get_post_meta_for_csv_export_map = array(
			array( 15, null ),
			array(
				16,
				array(
					'key3' => 'value3',
					'key4' => 'value4',
					'key5' => 'value5',
					'key6' => 'value6'
				)
			),
		);

		$get_parsed_field_contents_of_post_map = array(
			array( 15, array( '_feedback_subject' => 'subj1' ) ),
			array( 16, array( '_feedback_subject' => 'subj2' ) ),
		);


		$get_post_content_for_csv_export_map = array(
			array( 15, 'This is my test 15' ),
			array( 16, 'This is my test 16' ),
		);

		$mapped_fields_contents_map = array(
			array(
				array( '_feedback_subject' => 'subj1', '_feedback_main_comment' => 'This is my test 15' ),
				array(
					'Contact Form' => 'subj1',
					'4_Comment'    => 'This is my test 15'
				)
			),
			array(
				array( '_feedback_subject' => 'subj2', '_feedback_main_comment' => 'This is my test 16' ),
				array(
					'Contact Form' => 'subj2',
					'4_Comment'    => 'This is my test 16'
				)
			),
		);

		// Even though there is no post meta for the first, we don't stop the cycle
		// and each mock expects two calls
		$mock->expects( $this->exactly( 2 ) )
			->method( 'get_post_meta_for_csv_export' )
			->will( $this->returnValueMap( $get_post_meta_for_csv_export_map ) );

		$mock->expects( $this->exactly( 2 ) )
			->method( 'get_parsed_field_contents_of_post' )
			->will( $this->returnValueMap( $get_parsed_field_contents_of_post_map ) );

		$mock->expects( $this->exactly( 2 ) )
			->method( 'get_post_content_for_csv_export' )
			->will( $this->returnValueMap( $get_post_content_for_csv_export_map ) );

		$mock->expects( $this->exactly( 2 ) )
			->method( 'map_parsed_field_contents_of_post_to_field_names' )
			->will( $this->returnValueMap( $mapped_fields_contents_map ) );

		$result = $mock->get_export_data_for_posts( array( 15, 16 ) );

		$expected_result = array(
			'Contact Form' => array( 'subj1', 'subj2' ),
			'key3'         => array( '', 'value3' ),
			'key4'         => array( '', 'value4' ),
			'key5'         => array( '', 'value5' ),
			'key6'         => array( '', 'value6' ),
			'4_Comment'    => array( 'This is my test 15', 'This is my test 16' ),
		);

		$this->assertEquals( $expected_result, $result );
	}

	/**
	 * Test get_export_data_for_posts with invalid all entries for post meta
	 *
	 * @group csvexport
	 */
	public function test_get_export_data_for_posts_invalid_all_entries_meta() {
		/** @var Grunion_Contact_Form_Plugin $mock */
		$mock = $this->getMockBuilder( 'Grunion_Contact_Form_Plugin' )
		             ->setMethods( array(
			                           'get_post_meta_for_csv_export',
			                           'get_parsed_field_contents_of_post',
			                           'get_post_content_for_csv_export',
			                           'map_parsed_field_contents_of_post_to_field_names'

		                           ) )
		             ->disableOriginalConstructor()
		             ->getMock();


		$get_post_meta_for_csv_export_map = array(
			array( 15, null ),
			array( 16, null ),
		);

		$get_parsed_field_contents_of_post_map = array(
			array( 15, array( '_feedback_subject' => 'subj1' ) ),
			array( 16, array( '_feedback_subject' => 'subj2' ) ),
		);


		$get_post_content_for_csv_export_map = array(
			array( 15, 'This is my test 15' ),
			array( 16, 'This is my test 16' ),
		);

		$mapped_fields_contents_map = array(
			array(
				array( '_feedback_subject' => 'subj1', '_feedback_main_comment' => 'This is my test 15' ),
				array(
					'Contact Form' => 'subj1',
					'4_Comment'    => 'This is my test 15'
				)
			),
			array(
				array( '_feedback_subject' => 'subj2', '_feedback_main_comment' => 'This is my test 16' ),
				array(
					'Contact Form' => 'subj2',
					'4_Comment'    => 'This is my test 16'
				)
			),
		);

		$mock->expects( $this->exactly( 2 ) )
		     ->method( 'get_post_meta_for_csv_export' )
		     ->will( $this->returnValueMap( $get_post_meta_for_csv_export_map ) );


		$mock->expects( $this->exactly( 2 ) )
		     ->method( 'get_parsed_field_contents_of_post' )
		     ->will( $this->returnValueMap( $get_parsed_field_contents_of_post_map ) );

		$mock->expects( $this->exactly( 2 ) )
		     ->method( 'get_post_content_for_csv_export' )
		     ->will( $this->returnValueMap( $get_post_content_for_csv_export_map ) );


		$mock->expects( $this->exactly( 2 ) )
		     ->method( 'map_parsed_field_contents_of_post_to_field_names' )
		     ->will( $this->returnValueMap( $mapped_fields_contents_map ) );


		$result = $mock->get_export_data_for_posts( array( 15, 16 ) );

		$expected_result = array(
			'Contact Form' => array( 'subj1', 'subj2' ),
			'4_Comment'    => array( 'This is my test 15', 'This is my test 16' ),
		);

		$this->assertEquals( $expected_result, $result );
	}


	/**
	 * Test get_export_data_for_posts with single invalid entry for parsed fields.
	 *
	 * @group csvexport
	 */
	public function test_get_export_data_for_posts_single_invalid_entry_for_parse_fields() {
		/** @var Grunion_Contact_Form_Plugin $mock */
		$mock = $this->getMockBuilder( 'Grunion_Contact_Form_Plugin' )
		             ->setMethods( array(
			                           'get_post_meta_for_csv_export',
			                           'get_parsed_field_contents_of_post',
			                           'get_post_content_for_csv_export',
			                           'map_parsed_field_contents_of_post_to_field_names'

		                           ) )
		             ->disableOriginalConstructor()
		             ->getMock();


		$get_post_meta_for_csv_export_map = array(
			array(
				15,
				array(
					'key1' => 'value1',
					'key2' => 'value2',
					'key3' => 'value3',
					'key4' => 'value4',

				)
			),
			array(
				16,
				array(
					'key3' => 'value3',
					'key4' => 'value4',
					'key5' => 'value5',
					'key6' => 'value6'
				)
			),
		);

		$get_parsed_field_contents_of_post_map = array(
			array( 15, array() ),
			array( 16, array( '_feedback_subject' => 'subj2' ) ),
		);

		$get_post_content_for_csv_export_map = array(
			array( 15, 'This is my test 15' ),
			array( 16, 'This is my test 16' ),
		);

		$mapped_fields_contents_map = array(
			array(
				array( '_feedback_subject' => 'subj1', '_feedback_main_comment' => 'This is my test 15' ),
				array(
					'Contact Form' => 'subj1',
					'4_Comment'    => 'This is my test 15'
				)
			),
			array(
				array( '_feedback_subject' => 'subj2', '_feedback_main_comment' => 'This is my test 16' ),
				array(
					'Contact Form' => 'subj2',
					'4_Comment'    => 'This is my test 16'
				)
			),
		);

		$mock->expects( $this->exactly( 1 ) )
		     ->method( 'get_post_meta_for_csv_export' )
		     ->will( $this->returnValueMap( $get_post_meta_for_csv_export_map ) );

		$mock->expects( $this->exactly( 2 ) )
		     ->method( 'get_parsed_field_contents_of_post' )
		     ->will( $this->returnValueMap( $get_parsed_field_contents_of_post_map ) );

		$mock->expects( $this->exactly( 1 ) )
		     ->method( 'get_post_content_for_csv_export' )
		     ->will( $this->returnValueMap( $get_post_content_for_csv_export_map ) );

		$mock->expects( $this->exactly( 1 ) )
		     ->method( 'map_parsed_field_contents_of_post_to_field_names' )
		     ->will( $this->returnValueMap( $mapped_fields_contents_map ) );

		$result = $mock->get_export_data_for_posts( array( 15, 16 ) );

		$expected_result = array(
			'Contact Form' => array( 'subj2' ),
			'key3'         => array( 'value3' ),
			'key4'         => array( 'value4' ),
			'key5'         => array( 'value5' ),
			'key6'         => array( 'value6' ),
			'4_Comment'    => array( 'This is my test 16' ),
		);

		$this->assertEquals( $expected_result, $result );
	}


	/**
	 * Test get_export_data_for_posts with all entries for parsed fields invalid.
	 *
	 * @group csvexport
	 */
	public function test_get_export_data_for_posts_all_entries_for_parse_fields_invalid() {
		/** @var Grunion_Contact_Form_Plugin $mock */
		$mock = $this->getMockBuilder( 'Grunion_Contact_Form_Plugin' )
		             ->setMethods( array(
			                           'get_post_meta_for_csv_export',
			                           'get_parsed_field_contents_of_post',
			                           'get_post_content_for_csv_export',
			                           'map_parsed_field_contents_of_post_to_field_names'
		                           ) )
		             ->disableOriginalConstructor()
		             ->getMock();

		$get_parsed_field_contents_of_post_map = array(
			array( 15, array() ),
			array( 16, array() ),
		);

		$mock->expects( $this->never() )
		     ->method( 'get_post_meta_for_csv_export' );


		$mock->expects( $this->exactly( 2 ) )
		     ->method( 'get_parsed_field_contents_of_post' )
		     ->will( $this->returnValueMap( $get_parsed_field_contents_of_post_map ) );

		$result = $mock->get_export_data_for_posts( array( 15, 16 ) );

		$expected_result = array();

		$this->assertEquals( $expected_result, $result );
	}

	/**
	 * Test map_parsed_field_contents_of_post_to_field_names
	 *
	 * @group csvexport
	 */
	public function test_map_parsed_field_contents_of_post_to_field_names() {

		$input_data = array(
			'test_field'             => 'moonstruck',
			'_feedback_subject'      => 'This is my form',
			'_feedback_author_email' => '',
			'_feedback_author'       => 'John Smith',
			'_feedback_author_url'   => 'http://example.com',
			'_feedback_main_comment' => 'This is my comment!',
			'another_field'          => 'thunderstruck'
		);

		$plugin = Grunion_Contact_Form_Plugin::init();

		$result = $plugin->map_parsed_field_contents_of_post_to_field_names( $input_data );


		$expected_result = array(
			'Contact Form' => 'This is my form',
			'1_Name'       => 'John Smith',
			'3_Website'    => 'http://example.com',
			'4_Comment'    => 'This is my comment!'
		);

		$this->assertEquals( $expected_result, $result );
	}

	/**
	 * @author jaswrks
	 * @covers Grunion_Contact_Form::personal_data_exporter
	 * @covers Grunion_Contact_Form::personal_data_post_ids_by_email
	 * @covers Grunion_Contact_Form::personal_data_search_filter
	 */
	public function test_personal_data_exporter() {
		$this->add_field_values( array(
			'name'     => 'John Doe',
			'email'    => 'john@example.com',
			'dropdown' => 'First option',
			'radio'    => 'Second option',
			'text'     => 'Texty text'
		) );

		for ( $i = 1; $i <= 2; $i++ ) {
			$form = new Grunion_Contact_Form(
				array(
					'to'      => '"john" <john@example.com>',
					'subject' => 'Hello world! [ ' . mt_rand() .' ]',
				),
				'
					[contact-field label="Name" type="name" required="1"/]
					[contact-field label="Email" type="email" required="1"/]
					[contact-field label="Dropdown" type="select" options="First option,Second option,Third option"/]
					[contact-field label="Radio" type="radio" options="First option,Second option,Third option"/]
					[contact-field label="Text" type="text"/]
				'
			);
			$this->assertTrue(
				is_string( $form->process_submission() ),
				'form submission ' . $i
			);
		}

		$posts  = get_posts( array( 'post_type' => 'feedback' ) );
		$export = $this->plugin->personal_data_exporter( 'john@example.com' );

		$this->assertSame( 2, count( $posts ), 'posts count matches' );
		$this->assertSame( 2, count( $export['data'] ), 'export[data] count matches' );

		foreach ( $export['data'] as $data ) {
			$this->assertSame( 'feedback', $data['group_id'], 'group_id matches' );
			$this->assertSame( 'Feedback', $data['group_label'], 'group_label matches' );
			$this->assertSame( true, ! empty( $data['item_id'] ), 'has item_id key' );
			$this->assertSame( 6, count( $data['data'] ), 'has total expected data keys' );
		}
	}

	/**
	 * @author jaswrks
	 * @covers Grunion_Contact_Form::personal_data_eraser
	 * @covers Grunion_Contact_Form::personal_data_post_ids_by_email
	 * @covers Grunion_Contact_Form::personal_data_search_filter
	 */
	public function test_personal_data_eraser() {
		$this->add_field_values( array(
			'name'  => 'John Doe',
			'email' => 'john@example.com',
		) );

		for ( $i = 1; $i <= 2; $i++ ) {
			$form = new Grunion_Contact_Form(
				array(
					'to'      => '"john" <john@example.com>',
					'subject' => 'Hello world! [ ' . mt_rand() .' ]',
				),
				'
					[contact-field label="Name" type="name" required="1"/]
					[contact-field label="Email" type="email" required="1"/]
				'
			);
			$this->assertTrue(
				is_string( $form->process_submission() ),
				'form submission ' . $i
			);
		}

		$posts = get_posts( array( 'post_type' => 'feedback' ) );
		$this->assertSame( 2, count( $posts ), 'posts count matches before erasing' );

		$this->plugin->personal_data_eraser( 'john@example.com' );

		$posts = get_posts( array( 'post_type' => 'feedback' ) );
		$this->assertSame( 0, count( $posts ), 'posts count matches after erasing' );
	}

	public function test_personal_data_eraser_pagination() {
		$this->add_field_values( array(
			'name'  => 'Jane Doe',
			'email' => 'jane_doe@example.com',
		) );

		for ( $i = 1; $i <= 3; $i++ ) {
			$form = new Grunion_Contact_Form(
				array(
					'to'      => '"jane" <jane_doe@example.com>',
					'subject' => 'Hello world! [ ' . mt_rand() .' ]',
				),
				'
					[contact-field label="Name" type="name" required="1"/]
					[contact-field label="Email" type="email" required="1"/]
				'
			);
			$this->assertTrue(
				is_string( $form->process_submission() ),
				'form submission ' . $i
			);
		}

		$this->add_field_values( array(
			'name'  => 'Jane Doe Again',
			'email' => 'jane@example.com',
		) );


		$form = new Grunion_Contact_Form(
			array(
				'to'      => '"jane" <jane@example.com>',
				'subject' => 'Hello world! [ ' . mt_rand() .' ]',
			),
			'
				[contact-field label="Name" type="name" required="1"/]
				[contact-field label="Email" type="email" required="1"/]
			'
		);
		$this->assertTrue(
			is_string( $form->process_submission() ),
			'form submission ' . $i
		);

		$posts = get_posts( array( 'post_type' => 'feedback' ) );
		$this->assertSame( 4, count( $posts ), 'posts count matches before erasing' );

		$this->plugin->_internal_personal_data_eraser( 'jane_doe@example.com', 1, 1 );
		$posts = get_posts( array( 'post_type' => 'feedback' ) );
		$this->assertSame( 3, count( $posts ), 'posts count matches after page 1' );

		$this->plugin->_internal_personal_data_eraser( 'jane_doe@example.com', 2, 1 );
		$posts = get_posts( array( 'post_type' => 'feedback' ) );
		$this->assertSame( 2, count( $posts ), 'posts count matches after page 2' );

		$this->plugin->_internal_personal_data_eraser( 'jane_doe@example.com', 3, 1 );
		$posts = get_posts( array( 'post_type' => 'feedback' ) );
		$this->assertSame( 1, count( $posts ), 'posts count matches after page 3' );

		$this->plugin->_internal_personal_data_eraser( 'jane@example.com', 1, 1 );
		$posts = get_posts( array( 'post_type' => 'feedback' ) );
		$this->assertSame( 0, count( $posts ), 'posts count matches after deleting the other feedback responder' );
	}

} // end class