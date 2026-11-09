<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleOnCallCalendar\App\Controllers;

use MikoPBX\AdminCabinet\Controllers\BaseController;
use MikoPBX\Common\Providers\JwtProvider;
use Modules\ModuleOnCallCalendar\Models\Schedule;
use Phalcon\Mvc\Dispatcher;
use Throwable;

/**
 * Admin-cabinet page for On Call Calendar.
 *
 * Every request is checked for the admins role here, on top of the core's
 * SecurityPlugin: another module can grant other roles access to any
 * controller. The core's beforeExecuteRoute() returns void, so a refused
 * request is forwarded to deniedAction() rather than stopped.
 */
class ModuleOnCallCalendarController extends BaseController
{
    private const string ADMIN_ROLE = 'admins';
    private const string DENIED_ACTION = 'denied';

    public function beforeExecuteRoute(Dispatcher $dispatcher): void
    {
        parent::beforeExecuteRoute($dispatcher);

        if ($dispatcher->getActionName() === self::DENIED_ACTION) {
            return;
        }
        if ($this->currentRole() !== self::ADMIN_ROLE) {
            $dispatcher->forward(['action' => self::DENIED_ACTION]);
        }
    }

    /** The module page: the schedules and their numbers. */
    public function indexAction(): void
    {
        $this->view->setVar('schedules', Schedule::find(['order' => 'name'])->toArray());
    }

    /** 403 for anyone who is not an admin. */
    public function deniedAction(): void
    {
        $this->response->setStatusCode(403, 'Forbidden');
    }

    /**
     * The caller's role, resolved the same way as the core's SecurityPlugin:
     * the Bearer token first, then the refresh-token cookie of a page load.
     */
    private function currentRole(): ?string
    {
        $jwt = $this->di->getShared(JwtProvider::SERVICE_NAME);

        $role = $jwt->extractRoleFromHeader($this->request->getHeader('Authorization'));
        if ($role !== null) {
            return $role;
        }

        if ($this->cookies->has('refreshToken')) {
            try {
                $refreshToken = $this->cookies->get('refreshToken')->getValue();
                if (!empty($refreshToken)) {
                    return $jwt->extractRoleFromRefreshToken($refreshToken);
                }
            } catch (Throwable) {
                // An unreadable cookie counts as no role: refused.
            }
        }

        return null;
    }
}
