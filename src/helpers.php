<?php

/**
 * Converts a date string into RFC3339 format for google calendar api.
 * This is used for start/end dates for the creation/modification of events.
 * 
 * If $allDay and $isEnd are true, a day is added onto the date 
 * since the Google calendar thinks an all day event that spans
 * multiple days ends on the day before rather than consuming the day it
 * ends on.
 * 
 * Example: For an all day event that starts January 1st and ends January 3rd,
 * Google will end the event on the 2nd since the idea here is that the event
 * is 'till' the 3rd.
 * 
 * @param string $dateStr
 * @param boolean $allDay
 * @param boolean $isEnd
 * @return string
 */
if(!function_exists('strToRfc3339')) {
    
    function strToRfc3339($dateStr, $allDay = false, $isEnd = false)
    {
        $date = new \DateTime();

        /*
         * Check if the date is already in unix time or not
         */
        if(isValidTimeStamp($dateStr)) {
            $date->setTimestamp($dateStr);
        } else {
            $date->setTimestamp(strtotime($dateStr));
        }

        /*
         * If the event is all day, google only accepts Y-m-d formats instead
         * of RFC3339
         */
        if($allDay) {

            if($isEnd) {
                $date->add(new \DateInterval('P1D'));
            }

            return $date->format('Y-m-d');
        } else {
            return $date->format(\DateTime::RFC3339);
        }
    }
    
}

/**
 * Converts a date string into RFC2445 format for google calendar api.
 * This is used for recurrance rule dates
 */
if(!function_exists('strToRfc2445')) {
    
    function strToRfc2445($dateStr)
    {
        $date = new \DateTime();

        $date->setTimestamp(strtotime($dateStr));

        return $date->format('Ymd\THis\Z');
    }
    
}

/**
 * Checks if the inputted integer timestamp is a valid unix timestamp
 */
if(!function_exists('isValidTimeStamp')) {
    
    function isValidTimeStamp($timestamp)
    {
        return ((int) $timestamp === $timestamp) 
            && ($timestamp <= PHP_INT_MAX)
            && ($timestamp >= ~PHP_INT_MAX);
    }
    
}