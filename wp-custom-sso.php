<?php
/**
 * Plugin Name: Custom GraphQL Authentication Plugin Example
 * Description: Authenticates users and passwords from graphql.
 * Author: Fany Siswanto
 */

 add_filter( 'allow_password_reset', '__return_false' );

 // Register the custom REST API endpoint
 add_action('rest_api_init', 'fs_register_custom_rest_endpoint');

 function fs_register_custom_rest_endpoint() {
     // Endpoint URL: /wp-json/custom/v1/check-email
     register_rest_route('paywall/v1', '/check-email', array(
         'methods' => 'POST',
         'callback' => 'fs_handle_check_email_request',
     ));
 }

 // Custom REST API callback function
 function fs_handle_check_email_request($request) {
     // Get the JSON data from the request body
     $request_data = $request->get_json_params();

     // Check if the email address exists in the database
     $user_email = sanitize_email($request_data['email']);
     $user = get_user_by('email', $user_email);

    $valid_subscription=false;
        $item_sku = 'SKU1234'; // Replace with the actual SKU of the item you want to check

        // Get the user's subscriptions
        $subscriptions = wcs_get_users_subscriptions( $user->ID );

        // Check if the user has the specific item in any of their active subscriptions
        $has_item = false;
        foreach ( $subscriptions as $subscription ) {
                if ( $subscription->has_status( 'active' ) ) {
                        // Get the order for the subscription
                        $order = wc_get_order( $subscription->get_id() );

                        // Loop through the order items to check for the SKU
                        foreach ( $order->get_items() as $item ) {
                                // Get the product object for the item
                                $product = $item->get_product();
                                // Check if the product has the specified SKU
                                if ( $product && $product->get_sku() === $item_sku ) {
                                        $has_item = true;
                                        break 2; // Break both foreach loops
                                }
                        }
                }
        }

        if ( $has_item ) {
          // The user has the item in one of their active subscriptions
          $valid_subscription=true;
        }
     // Prepare the response data
     $response_data = array(
         'email' => $user_email,
         'exists' => $user !== false,
         'valid_subscription' => $valid_subscription,
     );

     // Return the response data
     return rest_ensure_response($response_data);
 }

// Register a custom login authentication function
add_filter('authenticate', 'fs_graphql_authenticate', 10, 3);

function fs_graphql_authenticate($user, $username, $password){
    // Check to see if they exist locally.
    $username=strtolower($username);
    $user_obj = get_user_by( 'email', $username);
    if ( false === $user_obj) {
        // try SSO, change this
        $graphql_api_url = 'https://graph.google.com';

        // Prepare the GraphQL query
        $query = '
        query LoginPaywallQuery($password: String!, $email: String!) {
            login_paywall(password: $password, email: $email)
        }
        ';

        // Define the GraphQL variables
        $variables = [
            'email' => $username,
            'password' => $password,
        ];

        // Make the GraphQL request using the WordPress HTTP API
        $response = wp_remote_post(
            $graphql_api_url,
            [
                'method' => 'POST',
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
                'body' => json_encode([
                    'query' => $query,
                    'variables' => $variables,
                ]),
            ]
        );
        // Check if the GraphQL request was successful
        if (is_wp_error($response)) {
            return new WP_Error('graphql_request_failed', __('Error connecting to GraphQL API.'));
        }

        // Parse the response and check if the authentication was successful
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if(isset($body['errors'][0]['message'])){
            return new WP_Error('SSO ERROR: '.$body['errors'][0]['message']);
        } else {
            if (isset($body['data']['login_paywall'])) {
                $user_data = json_decode($body['data']['login_paywall']);
                $fs_username = str_replace('@', '',$user_data->username);
                $insert_user_data = array(
                    'user_pass'         => $password,
                    'user_login'        => $fs_username,
                    'user_nicename'     => $fs_username,
                    'user_email'        => $user_data->email,
                    'display_name'      => $user_data->firstname . ' ' . $user_data->lastname,
                    'nickname'          => $fs_username,
                    'first_name'        => $user_data->firstname,
                    'last_name'         => $user_data->lastname,
                    'role'              => 'customer',
                );

                // If wp_insert_user() receives 'ID' in the array, it will update the
                // user data of an existing account instead of creating a new account.
                if ( false !== $user_obj && is_numeric( $user_obj->ID ) ) {
                    $insert_user_data['ID'] = $user_obj->ID;
                    $error_msg              = 'syncing';
                } else {
                    $error_msg = 'creating';
                }

                $new_user = wp_insert_user( $insert_user_data );

                // wp_insert_user() returns either int of the userid or WP_Error object.
                if ( is_wp_error( $new_user ) || ! is_int( $new_user ) ) {
                    do_action( 'wp_login_failed', $user_data['username'] ); // Fire any login-failed hooks.

                    // TODO: Add setting for support ticket URL.
                    $error_obj = new WP_Error(
                        'fs_SSO',
                        '<strong>ERROR:</strong> credentials are correct, but an error occurred ' . $error_msg . ' the local account. Please open a support ticket with this error.'
                    );
                    return $error_obj;
                } else {
                    // create meta data
                    update_user_meta( $userid, 'billing_first_name', $user_data->firstname );
                    update_user_meta( $userid, 'shipping_first_name', $user_data->firstname );
                    update_user_meta( $userid, 'billing_last_name', $user_data->lastname );
                    update_user_meta( $userid, 'shipping_last_name', $user_data->lastname );
                    if(strlen($user_data->gender)) { update_user_meta( $userid, ['gender'], $user_data->gender); }
                    if(strlen($user_data->phoneNo)) { update_user_meta( $userid, ['billing_phone'], $user_data->phoneNo); }
                    if(strlen($user_data->phoneNo)) { update_user_meta( $userid, ['shipping_phone'], $user_data->phoneNo); }

                    // Created the user successfully.
                    $user_obj = new WP_User( $new_user );
                    return $user_obj;
                }
            }
        }
    } else {
        return $user;
    }
}
