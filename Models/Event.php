<?php
/**
 * @copyright 2015 City of Bloomington, Indiana
 * @license http://www.gnu.org/licenses/agpl.txt GNU/AGPL, see LICENSE.txt
 * @author Cliff Ingham <inghamn@bloomington.in.gov>
 */
namespace Application\Models;

use Blossom\Classes\Database;
use iCal4PHP\Recur;

require_once GOOGLE.'/autoload.php';

class Event
{
    public $event;
    private $patch;

    private $data = [];

    public static $departments = [
        'BPW'    => 'Public Works',
        'CPT'    => 'Planning & Transportation',
        'STREET' => 'Street',
        'CBU'    => 'Utilities',
        'PROJ'   => 'External Project'
    ];

    public static $types = [
        'Road Closed'      => 'expect to detour, signage in place.',
        'Local Only'       => 'expect delays, signage in place.',
        'Reserved Meter'   => '',
        'Lane Restriction' => 'expect short delays, signage in place.',
        'Noise Permit'     => '',
        'Sidewalk'         => ''
    ];

    public function __construct($event=null)
    {
        if ($event) {
            if ($event instanceof \Google_Service_Calendar_Event) {
                $this->event = $event;
            }
            elseif (is_string($event)) {
                $this->event = GoogleGateway::getEvent(GOOGLE_CALENDAR_ID, $event);
                if (!$this->event) {
                    throw new \Exception('event/unknown');
                }
            }

            $this->parseSummary();
        }
        else {
            $this->event = new \Google_Service_Calendar_Event();
        }
    }

    public function validate()
    {
    }

    /**
     * Saves this event back to a Google Calendar
     *
     * If there is an event_id, it creates a new event
     * otherwise it checks to see if anything has been modified.
     * If fields have been modified, it sends a patch request.
     */
    public function save()
    {
        $summary = $this->getGeography_description();
        if ($this->getType())       { $summary = "{$this->getType()}-$summary"; }
        if ($this->getDepartment()) { $summary = "{$this->getDepartment()}-$summary"; }
        $this->setSummary($summary);

        $errors = $this->validate();
        if (!count($errors)) {
            if (!$this->getId()) {
                $event = GoogleGateway::insertEvent(GOOGLE_CALENDAR_ID, $this->event);
                $this->handleSubmission($event);
            }
            elseif ($this->patch instanceof \Google_Service_Calendar_Event) {
                $event = GoogleGateway::patchEvent(GOOGLE_CALENDAR_ID, $this->event->id, $this->patch);
                $this->handleSubmission($event);
            }
        }
        else {
            return $errors;
        }
    }
    private function handleSubmission($event)
    {
        if ($event instanceof \Google_Service_Calendar_Event) {
            $this->event = $event;
            $this->patch = null;
        }
        else {
            throw new Exception('event/saveError');
        }
    }

    /**
     * @param array $post
     */
    public function handleUpdate($post)
    {
        $fields = [
            'department', 'type',
            'description', 'geography', 'geography_description',
        ];
        foreach ($fields as $f) {
            $set = 'set'.ucfirst($f);
            $this->$set($post[$f]);
        }
    }

    /**
     * Updates values on the GoogleEvent object
     *
     * This function keeps track of which values have actually
     * changed, so we can submit a patch when we save the event
     * back to Google.
     */
    private function set($field, $value)
    {
        if (is_string($value)) {
            $value = trim($value);
            if ($this->event->$field == $value) {
                // Value has not changed - nothing to update
                return;
            }
        }

        if (!$this->patch) {
            $this->patch = new \Google_Service_Calendar_Event();
        }

        $set = 'set'.ucfirst($field);
        $this->event->$set($value);
        $this->patch->$set($value);
    }

    /**
     * @return string
     */
    public function getId()
    {
        return !empty($this->event->recurringEventId)
            ? $this->event->recurringEventId
            : $this->event->id;
    }
    public function getSummary()     { return $this->event->summary;     }
    public function getDescription() { return $this->event->description; }
    public function setSummary    ($s) { $this->set('summary',     $s); }
    public function setDescription($s) { $this->set('description', $s); }

    /**
     * @return bool
     */
    public function isAllDay()
    {
        return isset($this->event->start->dateTime) ? false : true;
    }

    /**
     * @param string $format
     * @return string
     */
    public function getStart($format=null)
    {
        if (!empty($this->event->start)) {
            $date = $this->event->start->dateTime
                ?   $this->event->start->dateTime
                :   $this->event->start->date;

            if ($format) {
                if (!($this->event->start->date && $format==TIME_FORMAT)) {
                    $d = new \DateTime($date);
                    return $d->format($format);
                }
            }
            else {
                return $date;
            }
        }
    }

    /**
     * @param string $format
     * @return string
     */
    public function getEnd($format=null)
    {
        if (!$this->event->endTimeUnspecified && !empty($this->event->end)) {
            $date = $this->event->end->dateTime
                ?   $this->event->end->dateTime
                :   $this->event->end->date;

            if ($format) {
                if (!($this->event->end->date && $format==TIME_FORMAT)) {
                    $d = new \DateTime($date);
                    return $d->format($format);
                }
            }
            else {
                return $date;
            }
        }
    }

    /**
     * @return Google_Service_Calendar_EventExtendedProperties
     */
    public function getExtendedProperties()
    {
        $properties = $this->event->getExtendedProperties();
        if (!$properties instanceof \Google_Service_Calendar_EventExtendedProperties) {
             $properties      = new \Google_Service_Calendar_EventExtendedProperties();
        }
        return $properties;
    }

    /**
     * @return string
     */
    public function getGeography()
    {
        $properties = $this->getExtendedProperties();

        $shared = $properties->getShared();
        if (!empty($shared['geography'])) {
            return $shared['geography'];
        }
    }

    /**
     * @param string $wkt Geometry in Well-Known Text format
     */
    public function setGeography($wkt)
    {
        $new = preg_replace('/[^A-Z0-9\s\(\)\,\-\.]/', '', $wkt);
        if ($this->getGeography() != $new) {
            $properties = $this->getExtendedProperties();
            $properties->setShared(['geography'=>$new]);

            $this->set('extendedProperties', $properties);
       }
    }

    /**
     * Parses structured data out of the summary string
     */
    private function parseSummary()
    {
        $d = implode('|',array_keys(self::$departments));
        if (preg_match("/^($d)(\s+)?-/i", $this->getSummary(), $matches)) {
            $this->data['department'] = strtoupper($matches[1]);
        }

        $d = implode('|', array_keys(self::$types));
        if (preg_match("/$d/i", $this->getSummary(), $matches)) {
            $this->data['type'] = ucwords(strtolower($matches[0]));
        }

        if (preg_match('/-([^-]+)$/', $this->getSummary(), $matches)) {
            $this->data['geography_description'] = trim($matches[1]);
        }
        else {
            $this->data['geography_description'] = $this->getSummary();
        }
    }
    public function getDepartment()            { return !empty($this->data['department'])            ? $this->data['department']            : ''; }
    public function getType()                  { return !empty($this->data['type'])                  ? $this->data['type']                  : ''; }
    public function getGeography_description() { return !empty($this->data['geography_description']) ? $this->data['geography_description'] : ''; }
    public function setDepartment($s) {
        if (array_key_exists($s, self::$departments)) {
            $this->data['department'] = strtoupper(trim($s));
        }
    }
    public function setType($s) {
        if (array_key_exists($s, self::$types)) {
            $this->data['type'] = ucwords(strtolower(trim($s)));
        }
    }
    public function setGeography_description($s) {
        $this->data['geography_description'] = trim($s);
    }

    /**
     * @return iCal4PHP\Recur
     */
    public function getRecur()
    {
        if ($this->event->recurrence) {
            foreach ($this->event->recurrence as $r) {
                if (strpos($r, 'RRULE:') !== false) {
                    $rrule = substr($r, 6);
                    return new Recur($rrule);
                }
            }
        }
    }
}