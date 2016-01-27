<?php

namespace Hipay\MiraklConnector\Cashout\Model\Operation;

use DateTime;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Operation interface
 * You must implement this class to use the library
 * Uses Symfony Validation assertion to ensure basic data integrity.
 *
 * @author    Ivanis Kouamé <ivanis.kouame@smile.fr>
 * @copyright 2015 Smile
 */

/**
 * Interface OperationInterface
 *
 * @Assert\Expression("is_int(this.getMiraklId()) || is_null(this.getMiraklId())")
 * @Assert\Expression("is_int(this.getHipayId()) || is_null(this.getMiraklId())")
 *
 * @package Hipay\MiraklConnector\Cashout\Model\Operation
 */
interface OperationInterface
{
    /**
     * @return int|null if it is an operator operation
     */
    public function getMiraklId();

    /**
     * @return int|null if the vendor didn't have its data in the db at the creation of the operation
     */
    public function getHipayId();

    /**
     * @return int
     *
     * @Assert\Type(type="integer")
     */
    public function getWithdrawId();

    /**
     * @param int $withdrawId
     */
    public function setWithdrawId($withdrawId);

    /**
     * @return int
     *
     * @Assert\Type(type="integer")
     */
    public function getTransferId();

    /**
     * @param $transferId
     */
    public function setTransferId($transferId);

    /**
     * @return int
     *
     * @Assert\NotBlank()
     * @Assert\Type(type="integer")
     */
    public function getStatus();

    /**
     * @param Status $status
     */
    public function setStatus(Status $status);

    /**
     * @return DateTime
     *
     * @Assert\NotBlank()
     * @Assert\DateTime()
     */
    public function getCycleDate();

    /**
     * @param DateTime $date
     */
    public function setCycleDate(DateTime $date);

    /**
     * @return float
     *
     * @Assert\NotBlank()
     * @Assert\GreaterThan(value = 0)
     * @Assert\Type(type="float")
     */
    public function getAmount();

    /**
     * @param float $amount
     */
    public function setAmount($amount);

    /**
     * @param $hipayId
     *
     * @return void
     */
    public function setHipayId($hipayId);

    /**
     * @param $miraklId
     *
     * @return void
     */
    public function setMiraklId($miraklId);
}
