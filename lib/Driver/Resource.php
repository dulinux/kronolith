<?php
/**
 * The Kronolith_Driver_Resource class implements the Kronolith_Driver API for
 * storing resource calendars in a SQL backend.
 *
 * Copyright 1999-2009 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file COPYING for license information (GPL). If you
 * did not receive this file, see http://www.fsf.org/copyleft/gpl.html.
 *
 * @author  Luc Saillard <luc.saillard@fr.alcove.com>
 * @author  Chuck Hagenbuch <chuck@horde.org>
 * @author  Jan Schneider <jan@horde.org>
 * @package Kronolith
 */
class Kronolith_Driver_Resource extends Kronolith_Driver_Sql
{

    /**
     * Lists all events that satisfy the given conditions.
     * (Need to override this here since we create a concrete event object).
     *
     * @param Horde_Date $startInterval  Start of range date object.
     * @param Horde_Date $endInterval    End of range data object.
     * @param string $conditions         Conditions, given as SQL clauses.
     * @param array $vals                SQL bind variables for use with
     *                                   $conditions clauses.
     *
     * @return array  Events in the given time range satisfying the given
     *                conditions.
     */
    protected function _listEventsConditional($startInterval, $endInterval,
                                            $conditions = '', $vals = array())
    {
        $q = 'SELECT event_id, event_uid, event_description, event_location,' .
            ' event_private, event_status, event_attendees,' .
            ' event_title, event_recurcount,' .
            ' event_recurtype, event_recurenddate, event_recurinterval,' .
            ' event_recurdays, event_start, event_end, event_allday,' .
            ' event_alarm, event_alarm_methods, event_modified,' .
            ' event_exceptions, event_creator_id, event_resources' .
            ' FROM ' . $this->_params['table'] .
            ' WHERE calendar_id = ? AND ((';
        $values = array($this->_calendar);
        if ($conditions) {
            $q .= $conditions . ')) AND ((';
            $values = array_merge($values, $vals);
        }

        $etime = $endInterval->format('Y-m-d H:i:s');
        $stime = null;
        if (isset($startInterval)) {
            $stime = $startInterval->format('Y-m-d H:i:s');
            $q .= 'event_end >= ? AND ';
            $values[] = $stime;
        }
        $q .= 'event_start <= ?) OR (';
        $values[] = $etime;
        if (isset($stime)) {
            $q .= 'event_recurenddate >= ? AND ';
            $values[] = $stime;
        }
        $q .= 'event_start <= ?' .
            ' AND event_recurtype <> ?))';
        array_push($values, $etime, Horde_Date_Recurrence::RECUR_NONE);

        /* Log the query at a DEBUG log level. */
        Horde::logMessage(sprintf('Kronolith_Driver_Sql::_listEventsConditional(): user = "%s"; query = "%s"; values = "%s"',
                                  Horde_Auth::getAuth(), $q, implode(',', $values)),
                          __FILE__, __LINE__, PEAR_LOG_DEBUG);

        /* Run the query. */
        $qr = $this->_db->query($q, $values);
        if (is_a($qr, 'PEAR_Error')) {
            Horde::logMessage($qr, __FILE__, __LINE__, PEAR_LOG_ERR);
            return $qr;
        }

        $events = array();
        $row = $qr->fetchRow(DB_FETCHMODE_ASSOC);
        while ($row && !is_a($row, 'PEAR_Error')) {
            /* If the event did not have a UID before, we need to give
             * it one. */
            if (empty($row['event_uid'])) {
                $row['event_uid'] = $this->generateUID();

                /* Save the new UID for data integrity. */
                $query = 'UPDATE ' . $this->_params['table'] . ' SET event_uid = ? WHERE event_id = ?';
                $values = array($row['event_uid'], $row['event_id']);

                /* Log the query at a DEBUG log level. */
                Horde::logMessage(sprintf('Kronolith_Driver_Sql::_listEventsConditional(): user = %s; query = "%s"; values = "%s"',
                                          Horde_Auth::getAuth(), $query, implode(',', $values)),
                                  __FILE__, __LINE__, PEAR_LOG_DEBUG);

                $result = $this->_write_db->query($query, $values);
                if (is_a($result, 'PEAR_Error')) {
                    Horde::logMessage($result, __FILE__, __LINE__, PEAR_LOG_ERR);
                }
            }

            /* We have all the information we need to create an event object
             * for this event, so go ahead and cache it. */
            $this->_cache[$this->_calendar][$row['event_id']] = new Kronolith_Event_Resource($this, $row);
            if ($row['event_recurtype'] == Horde_Date_Recurrence::RECUR_NONE) {
                $events[$row['event_uid']] = $row['event_id'];
            } else {
                $next = $this->nextRecurrence($row['event_id'], $startInterval);
                if ($next && $next->compareDateTime($endInterval) < 0) {
                    $events[$row['event_uid']] = $row['event_id'];
                }
            }

            $row = $qr->fetchRow(DB_FETCHMODE_ASSOC);
        }

        return $events;
    }

    public function getEvent($eventId = null)
    {
        if (!strlen($eventId)) {
            return new Kronolith_Event_Resource($this);
        }

        if (isset($this->_cache[$this->_calendar][$eventId])) {
            return $this->_cache[$this->_calendar][$eventId];
        }

        $query = 'SELECT event_id, event_uid, event_description,' .
            ' event_location, event_private, event_status, event_attendees,' .
            ' event_title, event_recurcount,' .
            ' event_recurtype, event_recurenddate, event_recurinterval,' .
            ' event_recurdays, event_start, event_end, event_allday,' .
            ' event_alarm, event_alarm_methods, event_modified,' .
            ' event_exceptions, event_creator_id, event_resources' .
            ' FROM ' . $this->_params['table'] . ' WHERE event_id = ? AND calendar_id = ?';
        $values = array($eventId, $this->_calendar);

        /* Log the query at a DEBUG log level. */
        Horde::logMessage(sprintf('Kronolith_Driver_Sql::getEvent(): user = "%s"; query = "%s"; values = "%s"',
                                  Horde_Auth::getAuth(), $query, implode(',', $values)),
                          __FILE__, __LINE__, PEAR_LOG_DEBUG);

        $event = $this->_db->getRow($query, $values, DB_FETCHMODE_ASSOC);
        if (is_a($event, 'PEAR_Error')) {
            Horde::logMessage($event, __FILE__, __LINE__, PEAR_LOG_ERR);
            return $event;
        }

        if ($event) {
            $this->_cache[$this->_calendar][$eventId] = new Kronolith_Event_Resource($this, $event);
            return $this->_cache[$this->_calendar][$eventId];
        } else {
            return PEAR::raiseError(_("Event not found"));
        }
    }

    /**
     * Get an event or events with the given UID value.
     *
     * @param string $uid The UID to match
     * @param array $calendars A restricted array of calendar ids to search
     * @param boolean $getAll Return all matching events? If this is false,
     * an error will be returned if more than one event is found.
     *
     * @return Kronolith_Event
     */
    public function getByUID($uid, $calendars = null, $getAll = false)
    {
        $query = 'SELECT event_id, event_uid, calendar_id, event_description,' .
            ' event_location, event_private, event_status, event_attendees,' .
            ' event_title, event_recurcount,' .
            ' event_recurtype, event_recurenddate, event_recurinterval,' .
            ' event_recurdays, event_start, event_end, event_allday,' .
            ' event_alarm, event_alarm_methods, event_modified,' .
            ' event_exceptions, event_creator_id, event_resources' .
            ' FROM ' . $this->_params['table'] . ' WHERE event_uid = ?';
        $values = array($uid);

        /* Optionally filter by calendar */
        if (!is_null($calendars)) {
            if (!count($calendars)) {
                return PEAR::raiseError(_("No calendars to search"));
            }
            $query .= ' AND calendar_id IN (?' . str_repeat(', ?', count($calendars) - 1) . ')';
            $values = array_merge($values, $calendars);
        }

        /* Log the query at a DEBUG log level. */
        Horde::logMessage(sprintf('Kronolith_Driver_Sql::getByUID(): user = "%s"; query = "%s"; values = "%s"',
                                  Horde_Auth::getAuth(), $query, implode(',', $values)),
                          __FILE__, __LINE__, PEAR_LOG_DEBUG);

        $events = $this->_db->getAll($query, $values, DB_FETCHMODE_ASSOC);
        if (is_a($events, 'PEAR_Error')) {
            Horde::logMessage($events, __FILE__, __LINE__, PEAR_LOG_ERR);
            return $events;
        }
        if (!count($events)) {
            return PEAR::raiseError($uid . ' not found');
        }

        $eventArray = array();
        foreach ($events as $event) {
            $this->open($event['calendar_id']);
            $this->_cache[$this->_calendar][$event['event_id']] = new Kronolith_Event_Resource($this, $event);
            $eventArray[] = $this->_cache[$this->_calendar][$event['event_id']];
        }

        if ($getAll) {
            return $eventArray;
        }

        /* First try the user's own calendars. */
        $ownerCalendars = Kronolith::listCalendars(true, PERMS_READ);
        $event = null;
        foreach ($eventArray as $ev) {
            if (isset($ownerCalendars[$ev->getCalendar()])) {
                $event = $ev;
                break;
            }
        }

        /* If not successful, try all calendars the user has access too. */
        if (empty($event)) {
            $readableCalendars = Kronolith::listCalendars(false, PERMS_READ);
            foreach ($eventArray as $ev) {
                if (isset($readableCalendars[$ev->getCalendar()])) {
                    $event = $ev;
                    break;
                }
            }
        }

        if (empty($event)) {
            $event = $eventArray[0];
        }

        return $event;
    }

    /**
     * Saves an event in the backend.
     * If it is a new event, it is added, otherwise the event is updated.
     *
     * @param Kronolith_Event $event  The event to save.
     */
    public function saveEvent($event)
    {
        if ($event->isStored() || $event->exists()) {
            $values = array();

            $query = 'UPDATE ' . $this->_params['table'] . ' SET ';

            foreach ($event->getProperties() as $key => $val) {
                $query .= " $key = ?,";
                $values[] = $val;
            }
            $query = substr($query, 0, -1);
            $query .= ' WHERE event_id = ?';
            $values[] = $event->getId();

            /* Log the query at a DEBUG log level. */
            Horde::logMessage(sprintf('Kronolith_Driver_Sql::saveEvent(): user = "%s"; query = "%s"; values = "%s"',
                                      Horde_Auth::getAuth(), $query, implode(',', $values)),
                              __FILE__, __LINE__, PEAR_LOG_DEBUG);

            $result = $this->_write_db->query($query, $values);
            if (is_a($result, 'PEAR_Error')) {
                Horde::logMessage($result, __FILE__, __LINE__, PEAR_LOG_ERR);
                return $result;
            }

            /* Log the modification of this item in the history log. */
            if ($event->getUID()) {
                $history = Horde_History::singleton();
                $history->log('kronolith:' . $this->_calendar . ':' . $event->getUID(), array('action' => 'modify'), true);
            }

            /* Update tags */
            $tagger = Kronolith::getTagger();
            $tagger->replaceTags($event->getUID(), $event->tags, 'event');

            /* Notify users about the changed event. */
            $result = Kronolith::sendNotification($event, 'edit');
            if (is_a($result, 'PEAR_Error')) {
                Horde::logMessage($result, __FILE__, __LINE__, PEAR_LOG_ERR);
            }

            return $event->getId();
        } else {
            if ($event->getId()) {
                $id = $event->getId();
            } else {
                $id = hash('md5', uniqid(mt_rand(), true));
                $event->setId($id);
            }

            if ($event->getUID()) {
                $uid = $event->getUID();
            } else {
                $uid = (string)new Horde_Support_Guid;
                $event->setUID($uid);
            }

            $query = 'INSERT INTO ' . $this->_params['table'];
            $cols_name = ' (event_id, event_uid,';
            $cols_values = ' VALUES (?, ?,';
            $values = array($id, $uid);

            foreach ($event->getProperties() as $key => $val) {
                $cols_name .= " $key,";
                $cols_values .= ' ?,';
                $values[] = $val;
            }

            $cols_name .= ' calendar_id)';
            $cols_values .= ' ?)';
            $values[] = $this->_calendar;

            $query .= $cols_name . $cols_values;

            /* Log the query at a DEBUG log level. */
            Horde::logMessage(sprintf('Kronolith_Driver_Sql::saveEvent(): user = "%s"; query = "%s"; values = "%s"',
                                Horde_Auth::getAuth(), $query, implode(',', $values)),
                                __FILE__, __LINE__, PEAR_LOG_DEBUG);

            $result = $this->_write_db->query($query, $values);
            if (is_a($result, 'PEAR_Error')) {
                Horde::logMessage($result, __FILE__, __LINE__, PEAR_LOG_ERR);
                return $result;
            }

            /* Log the creation of this item in the history log. */
            $history = Horde_History::singleton();
            $history->log('kronolith:' . $this->_calendar . ':' . $uid, array('action' => 'add'), true);

            /* Deal with any tags */
            $tagger = Kronolith::getTagger();
            $tagger->tag($event->getUID(), $event->tags, 'event');

            /* Notify users about the new event. */
            $result = Kronolith::sendNotification($event, 'add');
            if (is_a($result, 'PEAR_Error')) {
                Horde::logMessage($result, __FILE__, __LINE__, PEAR_LOG_ERR);
            }

            return $id;
        }
    }

    /**
     * Save or update a Kronolith_Resource
     *
     * @param Kronolith_Resource $resource
     *
     * @return Kronolith_Resource object
     * @throws Horde_Exception
     */
    public function save($resource)
    {
        if ($resource->getId()) {
            $query = 'UPDATE kronolith_resources SET resource_name = ?, resource_calendar = ?, resource_category = ? , resource_description = ?, resource_response_type = ?, resource_type = ?, resource_members = ? WHERE resource_id = ?';
            $values = array($resource->get('name'), $resource->get('calendar'), $resource->get('category'), $resource->get('description'), $resource->get('response_type'), $resource->get('type'), $resource->get('members'), $resource->getId());
            $result = $this->_write_db->query($query, $values);
            if ($result instanceof PEAR_Error) {
                throw new Horde_Exception($result->getMessage());
            }
        } else {
            $query = 'INSERT INTO kronolith_resources (resource_id, resource_name, resource_calendar, resource_category, resource_description, resource_response_type, resource_type, resource_members)';
            $cols_values = ' VALUES (?, ?, ?, ?, ?, ?, ?, ?)';
            $id = $this->_db->nextId('kronolith_resources');
            $values = array($id, $resource->get('name'), $resource->get('calendar'), $resource->get('category'), $resource->get('description'), $resource->get('response_type'), $resource->get('type'), $resource->get('members'));
            $result = $this->_write_db->query($query . $cols_values, $values);
            if ($result instanceof PEAR_Error) {
                throw new Horde_Exception($result->getMessage());
            }

            $resource->setId($id);
        }

        return $resource;
    }

    /**
     * Removes a resource from storage, along with any events in the resource's
     * calendar.
     *
     * @param Kronolith_Resource $resource  The kronolith resource to remove
     *
     * @return boolean
     * @throws Horde_Exception
     */
    public function delete($resource)
    {
        if (!($resource instanceof Kronolith_Resource_Base) || !$resource->getId()) {
            throw new Horde_Exception(_("Resource not valid."));
        }

        $query = 'DELETE FROM ' . $this->_params['table'] . ' WHERE calendar_id = ?';
        $result = $this->_write_db->query($query, array($resource->get('calendar')));
        if ($result instanceof PEAR_Error) {
            throw new Horde_Exception($result->getMessage());
        }
        $query = 'DELETE FROM kronolith_resources WHERE resource_id = ?';
        $result = $this->_write_db->query($query, array($resource->getId()));
        if ($result instanceof PEAR_Error) {
            throw new Horde_Exception($result->getMessage());
        }

        return true;
    }

    /**
     * Delete an event.
     *
     * Since this is the Kronolith_Resource's version of the event, if we
     * delete it, we must also make sure to remove it from the event that
     * it is attached to. Not sure if there is a better way to do this...
     *
     * @see lib/Driver/Kronolith_Driver_Sql#deleteEvent($eventId, $silent)
     */
    public function deleteEvent($event, $silent = false)
    {
        /* Since this is the Kronolith_Resource's version of the event, if we
         * delete it, we must also make sure to remove it from the event that
         * it is attached to. Not sure if there is a better way to do this...
         */
        $delete_event = $this->getEvent($event);
        $uid = $delete_event->getUID();
        $driver = Kronolith::getDriver();
        $events = $driver->getByUID($uid, null, true);
        foreach ($events as $e) {
            $resources = $e->getResources();
            if (count($resources)) {
                // found the right entry
                $r = $this->getResource($this->getResourceIdByCalendar($delete_event->getCalendar()));
                $e->removeResource($r);
                $e->save();
            }
        }

        parent::deleteEvent($event, $silent);
    }

    /**
     * Obtain a Kronolith_Resource by the resource's id
     *
     * @param int $id  The key for the Kronolith_Resource
     *
     * @return Kronolith_Resource_Single || Kronolith_Resource_Group
     * @throws Horde_Exception
     */
    public function getResource($id)
    {
        $query = 'SELECT resource_id, resource_name, resource_calendar, resource_category, resource_description, resource_response_type, resource_type, resource_members FROM kronolith_resources WHERE resource_id = ?';

        $results = $this->_db->getRow($query, array($id), DB_FETCHMODE_ASSOC);
        if ($results instanceof PEAR_Error) {
            throw new Horde_Exception($results->getMessage());
        }
        if (empty($results)) {
            throw new Horde_Exception('Resource not found');
        }

        $class = 'Kronolith_Resource_' . $results['resource_type'];
        if (!class_exists($class)) {
            throw new Horde_Exception(sprintf(_("Could not load the class definition for %s"), $class));
        }

        return new $class($this->_fromDriver($results));
    }

    /**
     * Obtain the resource id associated with the given calendar uid.
     *
     * @param string $calendar  The calendar's uid
     *
     * @return int  The Kronolith_Resource id
     * @throws Horde_Exception
     */
    public function getResourceIdByCalendar($calendar)
    {
        $query = 'SELECT resource_id FROM kronolith_resources WHERE resource_calendar = ?';
        $results = $this->_db->getOne($query, array($calendar));
        if ($results instanceof PEAR_Error) {
            throw new Horde_Exception($results->getMessage());
        }
        if (empty($results)) {
            throw new Horde_Exception('Resource not found');
        }

        return $results;
    }

    /**
     * Return a list of Kronolith_Resources
     *
     * This method will likely be a moving target as group resources are
     * fleshed out.
     *
     * @param array $filter  A hash of field/values to filter on.
     */
    public function listResources($filter = array())
    {
        $query = 'SELECT resource_id, resource_name, resource_calendar, resource_category, resource_description, resource_response_type, resource_type, resource_members FROM kronolith_resources';
        if (count($filter)) {
            $clause = ' WHERE ';
            $i = 0;
            $c = count($filter);
            foreach ($filter as $field => $value) {
                $clause .= 'resource_' . $field . ' = ?' . (($i++ < ($c - 1)) ? ' AND ' : '');
            }
            $query .= $clause;
        }

        $results = $this->_db->getAssoc($query, true, $filter, DB_FETCHMODE_ASSOC, false);
        if ($results instanceof PEAR_Error) {
            throw new Horde_Exception($results->getMessage());
        }

        $return = array();
        foreach ($results as $key => $result) {
            $class = 'Kronolith_Resource_' . $result['resource_type'];
            $return[$key] = new $class($this->_fromDriver(array_merge(array('resource_id' => $key), $result)));
        }

        return $return;
    }

    /**
     *
     * @param $params
     * @return unknown_type
     */
    protected function _fromDriver($params)
    {
        $return = array();
        foreach ($params as $field => $value) {
            $return[str_replace('resource_', '', $field)] = $this->convertFromDriver($value);
        }

        return $return;
    }

    /**
     * Remove all events owned by the specified user in all calendars.
     *
     * @todo Refactor: move to Kronolith::
     *
     * @param string $user  The user name to delete events for.
     *
     * @param mixed  True | PEAR_Error
     */
    public function removeUserData($user)
    {
        return PEAR::raiseError(_("Removing user data is not supported with the current calendar storage backend."));
    }

}
