<?php
/**
 *
 * @category
 *
 * @author    Ivanis Kouamé <ivanis.kouame@smile.fr>
 * @copyright 2015 Smile
 */
namespace HiPay\Wallet\Mirakl\Exception;

use Exception;

/**
 * Thrown when the operation field in the notification is not correct
 *
 * @author    Ivanis Kouamé <ivanis.kouame@smile.fr>
 * @copyright 2015 Smile.
 */
class IllegalNotificationOperationException extends Exception
{
    /**
     * @var string
     */
    protected $operation;

    /**
     * IllegalNotificationOperationException constructor.
     *
     * @param string    $operation
     * @param string    $message
     * @param int       $code
     * @param Exception $previous
     */
    public function __construct(
        $operation,
        $message = '',
        $code = 0,
        Exception $previous = null
    ) {
        $this->operation = $operation;
        parent::__construct(
            $message ?: "The operation $operation
        is not a viable notification operation",
            $code,
            $previous
        );
    }

    /**
     * @return string
     */
    public function getOperation()
    {
        return $this->operation;
    }
}
