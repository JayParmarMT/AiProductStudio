<?php
namespace Meetanshi\AiProductStudio\Model;

use Magento\Framework\Model\AbstractModel;

/**
 * Class Log
 *
 * Model for AI Product Studio request logs.
 */
class Log extends AbstractModel
{
    /**
     * Initialize resource model.
     *
     * @return void
     */
    protected function _construct()
    {
        $this->_init(\Meetanshi\AiProductStudio\Model\ResourceModel\Log::class);
    }
}
