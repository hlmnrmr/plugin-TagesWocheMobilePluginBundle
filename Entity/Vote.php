<?php
/**
 * @package Newscoop
 * @copyright 2012 Sourcefabric o.p.s.
 * @license http://www.gnu.org/licenses/gpl-3.0.txt
 */

namespace Newscoop\TagesWocheMobilePluginBundle\Entity;

use InvalidArgumentException;
use DateTime;
use Doctrine\ORM\Mapping AS ORM;
use Doctrine\Common\Collections\ArrayCollection;
use Newscoop\Entity\User;
use Newscoop\TagesWocheMobilePluginBundle\Entity\Debate;

/**
 * @ORM\Entity
 * @ORM\Table(name="plugin_debate_vote")
 */
class Vote
{
    /**
     * @ORM\Id @ORM\GeneratedValue
     * @ORM\Column(type="integer", name="id_vote")
     * @var int
     */
    private $id;

    /**
     * @ORM\ManyToOne(targetEntity="Newscoop\TagesWocheMobilePluginBundle\Entity\Debate", inversedBy="votes")
     * @ORM\JoinColumn(name="fk_debate_nr", referencedColumnName="debate_nr")
     * @var Tageswoche\Entity\Debate
     */
    private $debate;

    /**
     * @ORM\Column(type="integer", name="fk_answer_nr")
     * @var int
     */
    private $answer;

    /**
     * @ORM\ManyToOne(targetEntity="Newscoop\Entity\User")
     * @ORM\JoinColumn(name="fk_user_id", referencedColumnName="Id")
     * @var Newscoop\Entity\User
     */
    private $user;

    /**
     * @ORM\Column(type="string", length=80, name="device_id", nullable=true)
     * @var string
     */
    private $device;

    /**
     * @ORM\Column(type="datetime", name="added")
     * @var DateTime
     */
    private $added;

    /**
     * Get answer id
     *
     * @return int
     */
    public function getAnswerId()
    {
        return $this->answer;
    }

    /**
     * Test if vote was added between given dates
     *
     * @param string $from
     * @param string $to
     * @return bool
     */
    public function isWithin($from, $to)
    {
        $added = $this->added->format('Y-m-d');
        if (empty($from) && empty($to)) {
            return true;
        }

        return $added >= $from && $added < $to;
    }

    /**
     * Cast vote
     *
     * @param Newscoop\TagesWocheMobilePluginBundle\Entity\Debate $debate
     * @param int $answer
     * @param string $deviceId
     * @param Newscoop\Entity\User $user
     * @return void
     */
    public function cast(Debate $debate, $answer, $device = null, User $user = null)
    {
        if ($device === null && $user === null) {
            throw InvalidArgumentException("Device or user must be set");
        }

        $this->debate = $debate;
        $this->answer = $answer;
        $this->device = $device;
        $this->user = $user;
        $this->added = new DateTime('now');
    }
}
