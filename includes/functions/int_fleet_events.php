<?php

function flt_parse_objFleetList_to_events(FleetList $objFleetList, $planet_scanned = false) {
  global $config, $user, $fleet_number, $lang;

  $fleet_events = array();
  $fleet_number = 0;

  if($objFleetList->count() <= 0) {
    return;
  }

  foreach($objFleetList->_container as $objFleet) {
    $planet_start_type = $objFleet->fleet_start_type == PT_MOON ? PT_MOON : PT_PLANET;
    $planet_start = db_planet_by_gspt($objFleet->fleet_start_galaxy, $objFleet->fleet_start_system, $objFleet->fleet_start_planet, $planet_start_type, false, 'name');
    $objFleet->fleet_start_name = $planet_start['name'];

    $planet_end_type = $objFleet->fleet_end_type == PT_MOON ? PT_MOON : PT_PLANET;
    if($objFleet->fleet_end_planet > $config->game_maxPlanet) {
      $objFleet->fleet_end_name = $lang['ov_fleet_exploration'];
    } elseif($objFleet->mission_type == MT_COLONIZE) {
      $objFleet->fleet_end_name = $lang['ov_fleet_colonization'];
    } else {
      $planet_end = db_planet_by_gspt($objFleet->fleet_end_galaxy, $objFleet->fleet_end_system, $objFleet->fleet_end_planet, $planet_end_type, false, 'name');
      $objFleet->fleet_end_name = $planet_end['name'];
    }

    if($objFleet->time_arrive_to_target > SN_TIME_NOW && $objFleet->is_returning == 0 && $objFleet->mission_type != MT_MISSILE &&
      ($planet_scanned === false
        ||
        (
          $planet_scanned !== false
          && $planet_scanned['galaxy'] == $objFleet->fleet_end_galaxy && $planet_scanned['system'] == $objFleet->fleet_end_system && $planet_scanned['planet'] == $objFleet->fleet_end_planet && $planet_scanned['planet_type'] == $planet_end_type
          && $planet_start_type != PT_MOON
          && $objFleet->mission_type != MT_HOLD
        )
      )
    ) {
      $fleet_events[] = flt_register_event_objFleet($objFleet, 0, $planet_end_type);
    }

    if($objFleet->time_mission_job_complete > SN_TIME_NOW && $objFleet->is_returning == 0 && $planet_scanned === false && $objFleet->mission_type != MT_MISSILE) {
      $fleet_events[] = flt_register_event_objFleet($objFleet, 1, $planet_end_type);
    }

    if(
      $objFleet->time_return_to_source > SN_TIME_NOW && $objFleet->mission_type != MT_MISSILE && ($objFleet->is_returning == 1 || ($objFleet->mission_type != MT_RELOCATE && $objFleet->mission_type != MT_COLONIZE)) &&
      (
        ($planet_scanned === false && $objFleet->playerOwnerId == $user['id'])
        ||
        (
          $planet_scanned !== false
          && $objFleet->mission_type != MT_RELOCATE
          && $planet_start_type != PT_MOON
          && $planet_scanned['galaxy'] == $objFleet->fleet_start_galaxy && $planet_scanned['system'] == $objFleet->fleet_start_system && $planet_scanned['planet'] == $objFleet->fleet_start_planet && $planet_scanned['planet_type'] == $planet_start_type
        )
      )
    ) {
      $fleet_events[] = flt_register_event_objFleet($objFleet, 2, $planet_end_type);
    }

    if($objFleet->mission_type == MT_MISSILE) {
      $fleet_events[] = flt_register_event_objFleet($objFleet, 3, $planet_end_type);
    }
  }

  return $fleet_events;
}

/**
 * @param Fleet $objFleet
 * @param       $ov_label
 * @param       $planet_end_type
 *
 * @return mixed
 */
function flt_register_event_objFleet(Fleet $objFleet, $ov_label, $planet_end_type) {
  global $user, $planetrow, $fleet_number;

  $is_this_planet = false;

  switch($objFleet->ov_label = $ov_label) {
    case 0:
      $objFleet->event_time = $objFleet->time_arrive_to_target;
      $is_this_planet = (
        ($planetrow['galaxy'] == $objFleet->fleet_end_galaxy) AND
        ($planetrow['system'] == $objFleet->fleet_end_system) AND
        ($planetrow['planet'] == $objFleet->fleet_end_planet) AND
        ($planetrow['planet_type'] == $planet_end_type));
    break;

    case 1:
      $objFleet->event_time = $objFleet->time_mission_job_complete;
      $is_this_planet = (
        ($planetrow['galaxy'] == $objFleet->fleet_end_galaxy) AND
        ($planetrow['system'] == $objFleet->fleet_end_system) AND
        ($planetrow['planet'] == $objFleet->fleet_end_planet) AND
        ($planetrow['planet_type'] == $planet_end_type));
    break;

    case 2:
    case 3:
      $objFleet->event_time = $objFleet->time_return_to_source;
      $is_this_planet = (
        ($planetrow['galaxy'] == $objFleet->fleet_start_galaxy) AND
        ($planetrow['system'] == $objFleet->fleet_start_system) AND
        ($planetrow['planet'] == $objFleet->fleet_start_planet) AND
        ($planetrow['planet_type'] == $objFleet->fleet_start_type));
    break;

  }

  $objFleet->ov_this_planet = $is_this_planet;// || $planet_scanned != false;

  if($objFleet->playerOwnerId == $user['id']) {
    $user_data = $user;
  } else {
    $user_data = db_user_by_id($objFleet->playerOwnerId);
  }

  return tplParseFleetObject($objFleet, ++$fleet_number, $user_data);
}

function int_planet_pretemplate($planetrow, &$template)
{
  global $lang, $user;

  $governor_id = $planetrow['PLANET_GOVERNOR_ID'];
  $governor_level_plain = mrc_get_level($user, $planetrow, $governor_id, false, true);

  $template->assign_vars(array(
    'PLANET_ID'          => $planetrow['id'],
    'PLANET_NAME'        => htmlentities($planetrow['name'], ENT_QUOTES, 'UTF-8'),
    'PLANET_NAME_JS'     => htmlentities(js_safe_string($planetrow['name']), ENT_QUOTES, 'UTF-8'),
    'PLANET_GALAXY'      => $planetrow['galaxy'],
    'PLANET_SYSTEM'      => $planetrow['system'],
    'PLANET_PLANET'      => $planetrow['planet'],
    'PLANET_TYPE'        => $planetrow['planet_type'],
    'PLANET_TYPE_TEXT'   => $lang['sys_planet_type'][$planetrow['planet_type']],
    'PLANET_DEBRIS'      => $planetrow['debris_metal'] + $planetrow['debris_crystal'],

    'PLANET_GOVERNOR_ID'         => $governor_id,
    'PLANET_GOVERNOR_NAME'       => $lang['tech'][$governor_id],
    'PLANET_GOVERNOR_LEVEL'      => $governor_level_plain,
    'PLANET_GOVERNOR_LEVEL_PLUS' => mrc_get_level($user, $planetrow, $governor_id, false, false) - $governor_level_plain,
    'PLANET_GOVERNOR_LEVEL_MAX'  => get_unit_param($governor_id, P_MAX_STACK),
  ));
}
























//
//
//function flt_parse_fleets_to_events($fleet_list, $planet_scanned = false)
//{
//  global $config, $user, $fleet_number, $lang;
//
//  $fleet_events = array();
//  $fleet_number = 0;
//
//  if(empty($fleet_list))
//  {
//    return;
//  }
//
//  foreach($fleet_list as $fleet)
//  {
//    $planet_start_type = $fleet['fleet_start_type'] == PT_MOON ? PT_MOON : PT_PLANET;
//    $planet_start = db_planet_by_gspt($fleet['fleet_start_galaxy'], $fleet['fleet_start_system'], $fleet['fleet_start_planet'], $planet_start_type, false, 'name');
//    $fleet['fleet_start_name'] = $planet_start['name'];
//
//    $planet_end_type = $fleet['fleet_end_type'] == PT_MOON ? PT_MOON : PT_PLANET;
//    if($fleet['fleet_end_planet'] > $config->game_maxPlanet)
//    {
//      $fleet['fleet_end_name'] = $lang['ov_fleet_exploration'];
//    }
//    elseif($fleet['fleet_mission'] == MT_COLONIZE)
//    {
//      $fleet['fleet_end_name'] = $lang['ov_fleet_colonization'];
//    }
//    else
//    {
//      $planet_end = db_planet_by_gspt($fleet['fleet_end_galaxy'], $fleet['fleet_end_system'], $fleet['fleet_end_planet'], $planet_end_type, false, 'name');
//      $fleet['fleet_end_name'] = $planet_end['name'];
//    }
//
//    if($fleet['fleet_start_time'] > SN_TIME_NOW && $fleet['fleet_mess'] == 0 && $fleet['fleet_mission'] != MT_MISSILE &&
//      ($planet_scanned === false
//        ||
//        (
//          $planet_scanned !== false
//          && $planet_scanned['galaxy'] == $fleet['fleet_end_galaxy'] && $planet_scanned['system'] == $fleet['fleet_end_system'] && $planet_scanned['planet'] == $fleet['fleet_end_planet'] && $planet_scanned['planet_type'] == $planet_end_type
//          && $planet_start_type != PT_MOON
//          && $fleet['fleet_mission'] != MT_HOLD
//        )
//      )
//    )
//    {
//      $fleet_events[] = flt_register_fleet_event($fleet, 0, $planet_end_type);
//    }
//
//    if($fleet['fleet_end_stay'] > SN_TIME_NOW && $fleet['fleet_mess'] == 0 && $planet_scanned === false && $fleet['fleet_mission'] != MT_MISSILE)
//    {
//      $fleet_events[] = flt_register_fleet_event($fleet, 1, $planet_end_type);
//    }
//
//    if(
//      $fleet['fleet_end_time'] > SN_TIME_NOW && $fleet['fleet_mission'] != MT_MISSILE && ($fleet['fleet_mess'] == 1 || ($fleet['fleet_mission'] != MT_RELOCATE && $fleet['fleet_mission'] != MT_COLONIZE)) &&
//      (
//        ($planet_scanned === false && $fleet['fleet_owner'] == $user['id'])
//        ||
//        (
//          $planet_scanned !== false
//          && $fleet['fleet_mission'] != MT_RELOCATE
//          && $planet_start_type != PT_MOON
//          && $planet_scanned['galaxy'] == $fleet['fleet_start_galaxy'] && $planet_scanned['system'] == $fleet['fleet_start_system'] && $planet_scanned['planet'] == $fleet['fleet_start_planet'] && $planet_scanned['planet_type'] == $planet_start_type
//        )
//      )
//    )
//    {
//      $fleet_events[] = flt_register_fleet_event($fleet, 2, $planet_end_type);
//    }
//
//    if($fleet['fleet_mission'] == MT_MISSILE)
//    {
//      $fleet_events[] = flt_register_fleet_event($fleet, 3, $planet_end_type);
//    }
//  }
//
//  return $fleet_events;
//}
//
//function flt_register_fleet_event($fleet, $ov_label, $planet_end_type)
//{
//  global $user, $planetrow, $fleet_number;
//
//  switch($fleet['ov_label'] = $ov_label)
//  {
//    case 0:
//      $fleet['event_time'] = $fleet['fleet_start_time'];
//      $is_this_planet = (
//        ($planetrow['galaxy'] == $fleet['fleet_end_galaxy']) AND
//        ($planetrow['system'] == $fleet['fleet_end_system']) AND
//        ($planetrow['planet'] == $fleet['fleet_end_planet']) AND
//        ($planetrow['planet_type'] == $planet_end_type));
//    break;
//
//    case 1:
//      $fleet['event_time'] = $fleet['fleet_end_stay'];
//      $is_this_planet = (
//        ($planetrow['galaxy'] == $fleet['fleet_end_galaxy']) AND
//        ($planetrow['system'] == $fleet['fleet_end_system']) AND
//        ($planetrow['planet'] == $fleet['fleet_end_planet']) AND
//        ($planetrow['planet_type'] == $planet_end_type));
//    break;
//
//    case 2:
//    case 3:
//      $fleet['event_time'] = $fleet['fleet_end_time'];
//      $is_this_planet = (
//        ($planetrow['galaxy'] == $fleet['fleet_start_galaxy']) AND
//        ($planetrow['system'] == $fleet['fleet_start_system']) AND
//        ($planetrow['planet'] == $fleet['fleet_start_planet']) AND
//        ($planetrow['planet_type'] == $fleet['fleet_start_type']));
//    break;
//
//  }
//
//  $fleet['ov_this_planet'] = $is_this_planet;// || $planet_scanned != false;
//
//  if($fleet['fleet_owner'] == $user['id'])
//  {
//    $user_data = $user;
//  }
//  else
//  {
//    $user_data = db_user_by_id($fleet['fleet_owner']);
//  }
//
//  return tpl_parse_fleet_db($fleet, ++$fleet_number, $user_data);
//}
//

