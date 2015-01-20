<?php

namespace Stevebauman\CalendarHelper\Drivers;

use Illuminate\Support\Facades\File;
use Stevebauman\CalendarHelper\Objects\Attendee;
use Stevebauman\CalendarHelper\Objects\Event;
use Stevebauman\CalendarHelper\Drivers\DriverInterface;

class Google implements DriverInterface {

    /*
     * Holds the google client object
     */
    private $client;

    /*
     * Holds google service object
     */
    private $service;

    /*
     * Holds the google client api key
     */
    private $clientId;

    /*
     * Holds the google application name
     */
    private $applicationName;

    /*
     * Holds the google service account name
     */
    private $serviceAccountName;

    /*
     * Holds the google calendar ID
     */
    private $calendarId;

    /*
     * Holds the cert location
     */
    private $key;

    /*
     * Holds the google api scopes
     */
    private $scopes;

    public function __construct()
    {
        $this->calendarId = config('calendar-helper::google.default_calendar_id');
        $this->clientId = config('calendar-helper::google.client_id');
        $this->applicationName = config('calendar-helper::google.application_name');
        $this->serviceAccountName = config('calendar-helper::google.service_account_name');
        $this->scopes = config('calendar-helper::google.scopes');
        $this->key = File::get(config('calendar-helper::google.key'));

        $this->setUp();
    }

    /**
     * Performs set up operations such as google service/client object
     * creation
     */
    public function setUp()
    {
        /*
         * Create a new credentials object for authorization
         */
        $credentials = new \Google_Auth_AssertionCredentials(
            $this->serviceAccountName,
            $this->scopes,
            $this->key,
            'notasecret'
        );

        /*
         * Create a new client object
         */
        $this->client = new \Google_Client();

        /*
         * Set app name, client ID, and service credentials
         */
        $this->client->setApplicationName($this->applicationName);
        $this->client->setClientId($this->clientId);
        $this->client->setAssertionCredentials($credentials);

        /*
         * Create google calendar service and assign to class property
         */
        $this->service = new \Google_Service_Calendar($this->client);
    }


    /**
     * Overrides the default calendar id
     *
     * @param string $id
     * @return $this
     */
    public function setCalendarId($id)
    {
        if($id) {
            $this->calendarId = $id;
        }

        return $this;
    }

    /**
     * Retrieves a google API event. If the event retrieved is apart of a Google HTTP request, then it will
     * be returned since it's apart of a batch request. Otherwise it will be return an Event object. If the event
     * is not found (\Google_Service_Exception will be thrown) it will return false.
     *
     * @param string $apiId
     * @return bool|\Stevebauman\CalendarHelper\Objects\Event|\Google_Http_Request
     */
    public function event($apiId)
    {
        try {
            $googleEvent = $this->service->events->get($this->calendarId, $apiId);

            /*
             * If the google event is part of an HTTP request, return the request,
             * and if not, convert the google event into an event object and return it.
             * 
             * This is so a correct response is given if preparing a batch request
             */
            if($googleEvent instanceof \Google_Http_Request){
                return $googleEvent;
            } else {
                return $this->createEventObject($googleEvent);
            }

        } catch (\Google_Service_Exception $e) {
            /*
             * Not Found
             */
            return false;

        }
    }

    /**
     * Retrieves all events on the given calendar ID
     *
     * @param array $params
     * @return type
     */
    public function events(array $params = array())
    {
        $googleEvents = $this->service->events->listEvents($this->calendarId, $params);

        $events = array();

        foreach($googleEvents as $googleEvent) {

            $events[] = $this->createEventObject($googleEvent);

        }

        return $events;
    }

    /**
     * Retrieves a single events recurrences
     *
     * @param string $apiId
     * @param array $params
     * @return array
     */
    public function recurrences($apiId, array $params = array())
    {
        $googleEvents = $this->service->events->instances($this->calendarId, $apiId, $params);

        if($googleEvents instanceof \Google_Http_Request){

            return $googleEvents;

        } else {
            $events = array();

            foreach($googleEvents->getItems() as $googleEvent) {

                $events[] = $this->createEventObject($googleEvent);

            }

            return $events;
        }
    }

    /**
     * Retrieves events by the specified IDs
     *
     * @param array $apiIds
     * @param boolean $withRecurrences
     * @return object Collection
     */
    public function specificEvents(array $apiIds = array(), $withRecurrences = false, $recurParams = array())
    {
        $this->client->setUseBatch(true);

        $batch = new \Google_Http_Batch($this->client);

        $response = array();

        /*
         * For each google API id, add each event request into a batch, then
         * execute it
         */
        if(count($apiIds) > 0) {

            foreach($apiIds as $apiId) {

                $request = $this->event($apiId);

                $batch->add($request);

                /*
                 * Request the recurrences of the events if set to true
                 */
                if($withRecurrences) {
                    $request = $this->recurrences($apiId, $recurParams);

                    $batch->add($request);
                }

            }

            $response = $batch->execute();

        }

        $events = array();

        /*
         * For each retrieved google event, convert the google object into
         * an event object
         */
        if(count($response) > 0) {

            foreach($response as $googleEvent) {

                /*
                 * If recurrences are included, we need to remove the first recurrence
                 * of the event since the parent is included in the array
                 */
                if($googleEvent instanceof \Google_Service_Calendar_Events) {

                    $isFirst = true;

                    foreach($googleEvent as $recurrence) {

                        /*
                         * Make sure we skip the first recurrence
                         */
                        if($isFirst) {
                            $isFirst = false;
                            continue;
                        }

                        $events[] = $this->createEventObject($recurrence);

                    }

                } elseif($googleEvent instanceof \Google_Service_Calendar_Event) {

                    $events[] = $this->createEventObject($googleEvent);

                } elseif($googleEvent instanceof \Stevebauman\CalendarHelper\Objects\Event) {

                    $events[] = $googleEvent;

                } else {

                }

            }

        }

        return $events;
    }

    /**
     * Creates a new google calendar event
     *
     * @param \Stevebauman\CalendarHelper\Objects\Event $event
     * @return \Stevebauman\CalendarHelper\Objects\Event
     */
    public function createEvent(Event $event)
    {
        $this->setCalendarId($event->calendar_id);

        /*
         * Create new google event object
         */
        $googleEvent = new \Google_Service_Calendar_Event();

        /*
         * Set Details
         */
        $googleEvent->setSummary($event->title);
        $googleEvent->setDescription($event->description);
        $googleEvent->setLocation($event->location);

        /*
         * Set Start Date
         */
        $start = $this->createDateTime($event->start, $event->timeZone, $event->all_day);
        $googleEvent->setStart($start);

        /*
         * Set End Date
         */
        $end = $this->createDateTime($event->end, $event->timeZone, $event->all_day);
        $googleEvent->setEnd($end);

        /*
         * Set Recurrence Rule, make sure it's not empty
         */
        if($event->rrule) {
            $googleEvent->setRecurrence(array($event->rrule));
        }

        /*
         * Create the event
         */
        $newGoogleEvent = $this->service->events->insert($this->calendarId, $googleEvent);

        return $this->createEventObject($newGoogleEvent);
    }

    /**
     * Updates a google calendar event
     *
     * @param \Stevebauman\CalendarHelper\Objects\Event $event
     * @return \Stevebauman\CalendarHelper\Objects\Event
     */
    public function updateEvent(Event $event)
    {
        /*
         * Set Details
         */
        $event->apiObject->setSummary($event->title);

        $event->apiObject->setDescription($event->description);

        $event->apiObject->setLocation($event->location);

        /*
         * Set Start Date
         */
        $start = $this->createDateTime($event->start, $event->timeZone, $event->allDay);
        $event->apiObject->setStart($start);

        /*
         * Set End Date
         */
        $end = $this->createDateTime($event->end, $event->timeZone, $event->allDay);
        $event->apiObject->setEnd($end);

        /*
         * Set Recurrence Rule, make sure it's not empty
         */
        if($event->rrule) {
            $event->apiObject->setRecurrence(array($event->rrule));
        }

        /*
         * Create the event
         */
        $updatedEvent = $this->service->events->update($this->calendarId, $event->apiObject->getId(), $event->apiObject);

        return $this->createEventObject($updatedEvent);
    }

    /**
     * Removes the specified google calendar event
     *
     * @param string $eventId
     * @return boolean
     */
    public function deleteEvent($eventId)
    {
        try {

            $this->service->events->delete($this->calendarId, $eventId);

            return true;
        } catch(\Google_Service_Exception $e) {
            //Catch Not Found exception if calendar event does not exist
            return false;
        }
    }

    /**
     * Converts a Google Calendar Event into a standard Event object
     *
     * @param \Google_Service_Calendar_Event $googleEvent
     * @return \Stevebauman\CalendarHelper\Objects\Event
     */
    private function createEventObject(\Google_Service_Calendar_Event $googleEvent)
    {
        /*
         * If dateTime property is set on the google object, 
         * then the event is not all day
         */
        if($googleEvent->getStart()->dateTime) {

            $allDay = false;
            $start = $googleEvent->getStart()->dateTime;
            $end = $googleEvent->getEnd()->dateTime;

        } else {

            $allDay = true;
            $start = $googleEvent->getStart()->date;
            $end = $googleEvent->getEnd()->date;

        }

        /*
         * Create the new event object
         */
        $event = new Event(array(
            'calendar_id' => $this->calendarId,
            'id' => $googleEvent->getId(),
            'parent_id' => $googleEvent->getRecurringEventId(),
            'title' => $googleEvent->getSummary(),
            'description' => $googleEvent->getDescription(),
            'location' => $googleEvent->getLocation(),
            'status' => $googleEvent->getStatus(),
            'start' => $start,
            'end' => $end,
            'location' => $googleEvent->getLocation(),
            'all_day' => $allDay,
            'rrule' => ($googleEvent->getRecurrence() ? $googleEvent->getRecurrence()[0] : NULL),
            'isRecurrence' => ($googleEvent->recurringEventId ? true : false)
        ));

        /*
         * Assign the api object
         */
        $event->apiObject = $googleEvent;

        $attendees = array();

        /*
         * Assign each attendee their own object
         */
        if(count($googleEvent->getAttendees()) > 0) {
            foreach($googleEvent->getAttendees() as $attendee) {
                $attendees[] = $this->createAttendeeObject($attendee, $event);
            }
        }

        /*
         * Assign the event attendees
         */
        $event->attendees = $attendees;

        return $event;
    }

    /**
     * Converts a Google Calendar Event Attendee object into a standard Attendee
     * object
     *
     * @param \Google_Service_Calendar_EventAttendee $googleAttendee
     * @return \Stevebauman\CalendarHelper\Objects\Attendee
     */
    private function createAttendeeObject(\Google_Service_Calendar_EventAttendee $googleAttendee, Event $event)
    {
        $attendee = new Attendee(array(
            'id' => $googleAttendee->getId(),
            'name' => $googleAttendee->getDisplayName(),
            'status' => $googleAttendee->getResponseStatus(),
            'email' => $googleAttendee->getEmail(),
            'comment' => $googleAttendee->getComment()
        ));

        $attendee->event = $event;
        $attendee->apiObject = $googleAttendee;

        return $attendee;
    }

    /**
     * Creates a Google EventDateTime object
     *
     * @param string $dateTime
     * @param string $timeZone
     * @param boolean $allDay
     * @return \Google_Service_Calendar_EventDateTime
     */
    private function createDateTime($dateTime, $timeZone, $allDay = false)
    {
        $date = new \Google_Service_Calendar_EventDateTime();

        /*
         * If an event is all day, only the date must be set
         * 
         * ex. 
         *  - All Day - YYYY-MM-DD
         *  - Not All Day - YYYY-MM-DD\TH:I:S:
         */
        if($allDay) {
            $date->setDate($dateTime);
        } else {
            $date->setDateTime($dateTime);
        }

        $date->setTimeZone($timeZone);

        return $date;
    }


}