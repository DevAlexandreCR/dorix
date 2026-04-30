import type { RouteRecordRaw } from 'vue-router';
import AuthLayout from '../layouts/AuthLayout.vue';
import WorkspaceLayout from '../layouts/WorkspaceLayout.vue';
import LoginView from '../modules/auth/views/LoginView.vue';
import AdminView from '../modules/admin/views/AdminView.vue';
import OperationsView from '../modules/operations/views/OperationsView.vue';
import SandboxView from '../modules/sandbox/views/SandboxView.vue';

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
        component: AdminView,
        meta: {
          requiresAuth: true,
          section: 'admin',
          titleKey: 'admin.tab',
        },
      },
    ],
  },
];
