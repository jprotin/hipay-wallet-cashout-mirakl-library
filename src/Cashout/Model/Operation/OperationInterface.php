<?php
namespace Hipay\MiraklConnector\Cashout\Model\Operation;

use DateTime;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Operation interface
 * You must implement this class to use the library
 * Uses Symfony Validation assertion to ensure basic data integrity
 *
 * @author    Ivanis Kouamé <ivanis.kouame@smile.fr>
 * @copyright 2015 Smile
 */
interface OperationInterface
{
    /**
     * @return int|false if it is an operator operation
     */
    public function getMiraklId();

    /**
     * @Assert\NotBlank()
     * @Assert\Type(type="integer")
     *
     * @return int
     */
    public function getHipayId();

    /**
     * @return int
     * @Assert\Type(type="integer")
     */
    public function getHipayTransfertId();

    /**
     * @return int
     * @Assert\Type(type="integer")
     */
    public function getHipayWithdrawId();

    /**
     * @Assert\NotBlank()
     * @Assert\Type(type="integer")
     *
     * @return int
     */
    public function getStatus();

    /**
     * @param Status $status
     * @return void
     */
    public function setStatus(Status $status);

    /**
     * @return DateTime
     */
    public function getCycleStartDate();

    /**
     * @return DateTime
     */
    public function getCycleEndDate();
}