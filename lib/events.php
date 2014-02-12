<?php
/**
 * All event handlers for this plugin a in this file
 */

/**
 * When a user joins a group
 *
 * @param string $event  join
 * @param string $type   group
 * @param array  $params array with the user and the user
 *
 * @return void
 */
function group_tools_join_group_event($event, $type, $params) {
	global $NOTIFICATION_HANDLERS;
	
	static $auto_notification;
	
	// only load plugin setting once
	if (!isset($auto_notification)) {
		$auto_notification = array();

		if (isset($NOTIFICATION_HANDLERS) && is_array($NOTIFICATION_HANDLERS)) {
			if (elgg_get_plugin_setting("auto_notification", "group_tools") == "yes") { // Backwards compatibility
				$auto_notification = array("email", "site");
			}

			foreach ($NOTIFICATION_HANDLERS as $method=>$foo) {
 				if (elgg_get_plugin_setting("auto_notification_".$method, "group_tools") == "1") {
 					$auto_notification[] = $method;
				}
 			}
		}
	}
	
	if (!empty($params) && is_array($params)) {
		$group = elgg_extract("group", $params);
		$user = elgg_extract("user", $params);
		
		if (($user instanceof ElggUser) && ($group instanceof ElggGroup)) {
			if (!empty($NOTIFICATION_HANDLERS) && is_array($NOTIFICATION_HANDLERS)) {
				foreach ($NOTIFICATION_HANDLERS as $method => $dummy) {
					if (in_array($method, $auto_notification)) {
						add_entity_relationship($user->getGUID(), "notify" . $method, $group->getGUID());
					}
				}
			}
			
			// cleanup invites
			remove_entity_relationship($group->getGUID(), "invited", $user->getGUID());
			
			// and requests
			remove_entity_relationship($user->getGUID(), "membership_request", $group->getGUID());
			
			// cleanup email invitations
			$options = array(
				"annotation_name" => "email_invitation",
				"annotation_value" => group_tools_generate_email_invite_code($group->getGUID(), $user->email),
				"limit" => false
			);
			
			if (elgg_is_logged_in()) {
				elgg_delete_annotations($options);
			} elseif ($annotations = elgg_get_annotations($options)) {
				group_tools_delete_annotations($annotations);
			}
		}
	}
}

/**
 * Event when the user joins a site, mostly when registering
 *
 * @param string           $event        create
 * @param string           $type         member_of_site
 * @param ElggRelationship $relationship the membership relation
 *
 * @return void
 */
function group_tools_join_site_handler($event, $type, $relationship) {
	
	if (!empty($relationship) && ($relationship instanceof ElggRelationship)) {
		$user_guid = $relationship->guid_one;
		$site_guid = $relationship->guid_two;
		
		$user = get_user($user_guid);
		if (!empty($user)) {
			// ignore access
			$ia = elgg_set_ignore_access(true);
			
			// add user to the auto join groups
			$auto_joins = elgg_get_plugin_setting("auto_join", "group_tools");
			if (!empty($auto_joins)) {
				$auto_joins = string_to_tag_array($auto_joins);
				
				foreach ($auto_joins as $group_guid) {
					$group = get_entity($group_guid);
					if (!empty($group) && ($group instanceof ElggGroup)) {
						if ($group->site_guid == $site_guid) {
							// join the group
							$group->join($user);
						}
					}
				}
			}
			
			// auto detect email invited groups
			$groups = group_tools_get_invited_groups_by_email($user->email, $site_guid);
			if (!empty($groups)) {
				foreach ($groups as $group) {
					// join the group
					$group->join($user);
				}
			}
			
			// restore access settings
			elgg_set_ignore_access($ia);
		}
	}
}

/**
 * Event to remove the admin role when a user leaves a group
 *
 * @param string $event  leave
 * @param string $type   group
 * @param array  $params array with the user and the group
 *
 * @return void|boolean
 */
function group_tools_multiple_admin_group_leave($event, $type, $params) {

	if (!empty($params) && is_array($params)) {
		if (array_key_exists("group", $params) && array_key_exists("user", $params)) {
			$entity = $params["group"];
			$user = $params["user"];

			if (($entity instanceof ElggGroup) && ($user instanceof ElggUser)) {
				if (check_entity_relationship($user->getGUID(), "group_admin", $entity->getGUID())) {
					return remove_entity_relationship($user->getGUID(), "group_admin", $entity->getGUID());
				}
			}
		}
	}
}

/**
 * Notify the group admins about a membership request
 *
 * @param string           $event        create
 * @param string           $type         membership_request
 * @param ElggRelationship $relationship the created membership request relation
 *
 * @return void
 */
function group_tools_membership_request($event, $type, $relationship) {
	if (!($relationship instanceof ElggRelationship)) {
		return;
	}
	
	$group = get_entity($relationship->guid_two);
	$user = get_user($relationship->guid_one);
	
	if (!elgg_instanceof($group, 'group') || !elgg_instanceof($user, 'user')) {
		return;
	}
	
	// only send a message if group admins are allowed
	if (elgg_get_plugin_setting("multiple_admin", "group_tools") != "yes") {
		return;
	}
	
	// Notify group admins
	$options = array(
		"relationship" => "group_admin",
		"relationship_guid" => $group->getGUID(),
		"inverse_relationship" => true,
		"type" => "user",
		"limit" => false,
		"wheres" => array("e.guid <> " . $group->owner_guid),
		"callback" => false
	);
	
	$admins = elgg_get_entities_from_relationship($options);
	if (!empty($admins)) {
		$url = elgg_get_site_url() . "groups/requests/" . $group->getGUID();
		$subject = elgg_echo("groups:request:subject", array(
			$user->name,
			$group->name,
		));
		
		foreach ($admins as $a) {
			$body = elgg_echo("groups:request:body", array(
				$a->name,
				$user->name,
				$group->name,
				$user->getURL(),
				$url,
			));
			
			notify_user($a->getGUID(), $user->getGUID(), $subject, $body);
		}
	}
}
