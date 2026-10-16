<?php

use Uncanny_Automator\Recipe;

class Automator_Send_Message_Action {
	use Recipe\Actions;


	public function __construct() {
		$this->setup_action();
	}

	protected function setup_action() {

		$this->set_integration( 'BETTER_MESSAGES' );
		$this->set_action_code( 'BM_SEND_MESSAGE' );
		$this->set_action_meta( 'BM_SEND_PRIVATE_MESSAGE' );
		/* translators: Action - WordPress */
		$this->set_sentence( sprintf( esc_attr_x( 'Send {{a private message:%1$s}} to the user', 'Uncanny Automator Integration', 'bp-better-messages' ), $this->get_action_meta() ) );
		/* translators: Action - WordPress */
		$this->set_readable_sentence( esc_attr_x( 'Send {{a private message}} to the user', 'Uncanny Automator Integration', 'bp-better-messages' ) );

		$options_group = array(
			$this->get_action_meta() => array(
                Automator()->helpers->recipe->field->int([
                    'option_code' => 'BM_SENDER',
                    'label' => esc_attr_x( 'Sender', 'Uncanny Automator Integration', 'bp-better-messages' ),
                    'tokens' => true,
                    'default' => '',
                    'required' => true,
                    'description' => esc_attr_x( 'Accepts User ID', 'Uncanny Automator Integration', 'bp-better-messages' ),
                ]),
                Automator()->helpers->recipe->field->int([
                    'option_code' => 'BM_RECIPIENT',
                    'label' => esc_attr_x( 'Recipient', 'Uncanny Automator Integration', 'bp-better-messages' ),
                    'tokens' => true,
                    'default' => '',
                    'required' => true,
                    'description' => esc_attr_x( 'Accepts User ID', 'Uncanny Automator Integration', 'bp-better-messages' ),
                ]),
                Automator()->helpers->recipe->field->text(
                    array(
                        'option_code' => 'BM_SUBJECT',
                        'label'       => 'Subject',
                        'input_type'  => 'text',
                        'required' => false,
                    )
                ),
                Automator()->helpers->recipe->field->text(
                    array(
                        'option_code' => 'BM_UNIQUE_TAG',
                        'label'       => 'Unique Tag',
                        'tokens'      => false,
                        'input_type'  => 'text',
                        'required'    => false,
                        'description' => esc_attr_x( 'If you dont want to create many conversations with same user. You need to put unique tag, which will create conversation or send reply to conversation within the unique tag. For example for all system messages you can put "system" tag and they all will be sending to same conversation.', 'Uncanny Automator Integration', 'bp-better-messages' ),
                    )
                ),
                array(
                    'option_code' => 'BM_CONTENT',
                    'label'       => 'Content',
                    'input_type'  => 'textarea',
                    'required' => true,
                ),
			),
		);

		$this->set_options_group( $options_group );

		$this->register_action();
	}

	protected function process_action( $user_id, $action_data, $recipe_id, $args, $parsed ) {
		$action_meta = $action_data['meta'];

        $sender_id = (int) Automator()->parse->text( $action_meta['BM_SENDER'], $recipe_id, $user_id, $args );
        $recipient = (int) Automator()->parse->text( $action_meta['BM_RECIPIENT'], $recipe_id, $user_id, $args );
        $subject   = Automator()->parse->text( $action_meta['BM_SUBJECT'], $recipe_id, $user_id, $args );
        $content   = Automator()->parse->text( $action_meta['BM_CONTENT'], $recipe_id, $user_id, $args );

		$data = array(
			'sender_id'  => $sender_id,
			'recipients' => $recipient,
			'subject'    => $subject,
			'content'    => $content,
            'return'     => 'wp_error',
		);

        $unique_tag = trim( Automator()->parse->text( $action_meta['BM_UNIQUE_TAG'], $recipe_id, $user_id, $args ) );

        if( ! empty( $unique_tag ) ){
            $user_ids   = [ $sender_id, $recipient ];

            $thread_id = Better_Messages()->functions->get_unique_conversation_id( $user_ids, $unique_tag, $subject );

            $data = array(
                'thread_id'  => $thread_id,
                'sender_id'  => $sender_id,
                'content'    => $content,
                'return'     => 'both',
                'error_type' => 'wp_error'
            );
        }

        $data['skip_moderation'] = true;

        $send = Better_Messages()->functions->new_message( $data );

		if ( is_wp_error( $send ) ) {
			$error_message = $send->get_error_message();
			Automator()->complete->action( $user_id, $action_data, $recipe_id, $error_message );
		}

		Automator()->complete->action( $user_id, $action_data, $recipe_id );
	}
}
