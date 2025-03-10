<?php

namespace Leantime\Domain\Connector\Controllers {

    use Leantime\Core\Controller;
    use Leantime\Domain\Auth\Models\Roles;
    use Leantime\Domain\Auth\Services\Auth;
    use Leantime\Domain\Connector\Services;

    /**
     *
     */
    class Show extends Controller
    {
        private Services\Providers $providerService;

        /**
         * constructor - initialize private variables
         *
         * @access public
         *
         */
        public function init(Services\Providers $projectService)
        {
            Auth::authOrRedirect([Roles::$owner, Roles::$admin, Roles::$manager, Roles::$editor]);
            $this->providerService = $projectService;
        }

        /**
         * get - handle get requests
         *
         * @access public
         *
         */
        public function get($params)
        {
            $providers = $this->providerService->getProviders();

            $this->tpl->assign("providers", $providers);

            $this->tpl->display('connector.show');
        }

        /**
         * post - handle post requests
         *
         * @access public
         *
         */
        public function post($params)
        {
            //Redirect.
        }
    }

}
