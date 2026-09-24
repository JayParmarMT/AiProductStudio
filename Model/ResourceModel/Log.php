<?php
namespace Meetanshi\AiProductStudio\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

/**
 * Class Log
 *
 * Resource model for AI Product Studio request logs.
 */
class Log extends AbstractDb
{
    /**
     * Initialize resource model.
     *
     * @return void
     */
    protected function _construct()
    {
        $this->_init('meetanshi_ai_product_studio_log', 'log_id');
    }
}
