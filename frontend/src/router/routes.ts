import type { RouteRecordRaw } from 'vue-router';
import AuthLayout from '../layouts/AuthLayout.vue';
import WorkspaceLayout from '../layouts/WorkspaceLayout.vue';
import LoginView from '../modules/auth/views/LoginView.vue';
import AdminLayout from '../modules/admin/views/AdminLayout.vue';
import PlatformLayout from '../modules/platform/views/PlatformLayout.vue';
import OperationsView from '../modules/operations/views/OperationsView.vue';
import SandboxView from '../modules/sandbox/views/SandboxView.vue';
import { ADMIN_ROUTE_REQUIRES } from '../modules/admin/router';
import { PLATFORM_ROUTE_REQUIRES } from '../modules/platform/router';

export const routes: RouteRecordRaw[] = [
  {
    path: '/login',
    component: AuthLayout,
    children: [
      {
        path: '',
        name: 'login',
        component: LoginView,
        meta: {
          guestOnly: true,
          titleKey: 'auth.title',
        },
      },
    ],
  },
  {
    path: '/',
    component: WorkspaceLayout,
    meta: {
      requiresAuth: true,
    },
    children: [
      {
        path: '',
        redirect: '/operations',
      },
      {
        path: 'operations',
        name: 'operations',
        component: OperationsView,
        meta: {
          requiresAuth: true,
          section: 'operations',
          titleKey: 'operations.tab',
        },
      },
      {
        path: 'sandbox',
        name: 'sandbox',
        component: SandboxView,
        meta: {
          requiresAuth: true,
          section: 'sandbox',
          titleKey: 'sandbox.tab',
        },
      },
      {
        path: 'admin',
        name: 'admin',
        component: AdminLayout,
        meta: {
          requiresAuth: true,
          section: 'admin',
          titleKey: 'admin.tab',
        },
        children: [
          {
            path: 'org/info',
            name: 'admin.org.info',
            component: () => import('../modules/admin/views/org/InfoView.vue'),
            meta: {
              requiresAuth: true,
              requires: ADMIN_ROUTE_REQUIRES['/admin/org/info'],
            },
          },
          {
            path: 'org/members',
            name: 'admin.org.members',
            component: () => import('../modules/admin/views/org/MembersView.vue'),
            meta: {
              requiresAuth: true,
              requires: ADMIN_ROUTE_REQUIRES['/admin/org/members'],
            },
          },
          {
            path: 'connect/lines',
            name: 'admin.connect.lines',
            component: () => import('../modules/admin/views/connect/LinesView.vue'),
            meta: {
              requiresAuth: true,
              requires: ADMIN_ROUTE_REQUIRES['/admin/connect/lines'],
            },
          },
          {
            path: 'connect/credentials',
            name: 'admin.connect.credentials',
            component: () => import('../modules/admin/views/connect/CredentialsView.vue'),
            meta: {
              requiresAuth: true,
              requires: ADMIN_ROUTE_REQUIRES['/admin/connect/credentials'],
            },
          },
          {
            path: 'connect/data',
            name: 'admin.connect.data',
            component: () => import('../modules/admin/views/connect/DataView.vue'),
            meta: {
              requiresAuth: true,
              requires: ADMIN_ROUTE_REQUIRES['/admin/connect/data'],
            },
          },
          {
            path: 'assistant/behavior',
            name: 'admin.assistant.behavior',
            component: () => import('../modules/admin/views/assistant/BehaviorView.vue'),
            meta: {
              requiresAuth: true,
              requires: ADMIN_ROUTE_REQUIRES['/admin/assistant/behavior'],
            },
          },
          {
            path: 'assistant/tools',
            name: 'admin.assistant.tools',
            component: () => import('../modules/admin/views/assistant/ToolsView.vue'),
            meta: {
              requiresAuth: true,
              requires: ADMIN_ROUTE_REQUIRES['/admin/assistant/tools'],
            },
          },
          {
            path: 'assistant/catalog',
            name: 'admin.assistant.catalog',
            component: () => import('../modules/admin/views/assistant/CatalogView.vue'),
            meta: {
              requiresAuth: true,
              requires: ADMIN_ROUTE_REQUIRES['/admin/assistant/catalog'],
            },
          },
          {
            path: 'activity',
            name: 'admin.activity',
            component: () => import('../modules/admin/views/activity/ActivityView.vue'),
            meta: {
              requiresAuth: true,
              requires: ADMIN_ROUTE_REQUIRES['/admin/activity'],
            },
          },
        ],
      },
      {
        path: 'platform',
        name: 'platform',
        component: PlatformLayout,
        meta: {
          requiresAuth: true,
          section: 'platform',
          titleKey: 'platform.tab',
        },
        children: [
          {
            path: '',
            redirect: { name: 'platform.tenants' },
          },
          {
            path: 'tenants',
            name: 'platform.tenants',
            component: () => import('../modules/platform/views/TenantsView.vue'),
            meta: {
              requiresAuth: true,
              requires: PLATFORM_ROUTE_REQUIRES['/platform/tenants'],
            },
          },
          {
            path: 'credentials',
            name: 'platform.credentials',
            component: () => import('../modules/platform/views/CredentialsView.vue'),
            meta: {
              requiresAuth: true,
              requires: PLATFORM_ROUTE_REQUIRES['/platform/credentials'],
            },
          },
        ],
      },
    ],
  },
];
