/**
 * Al-Nikah Matrimonial Services - Headless API Client
 * Vanilla JavaScript Integration for pure HTML/CSS/JS Frontend
 */
const AlNikahAPI = (function () {
  // Auto-detect API base URL (defaults to Laravel serve port 8000)
  const isLocalHost = window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1' || window.location.protocol === 'file:' || !window.location.hostname;
  const API_BASE = window.ALNIKAH_API_URL || (isLocalHost ? 'http://127.0.0.1:8000/api' : '/api');
  const BACKEND_URL = window.ALNIKAH_BACKEND_URL || (isLocalHost ? 'http://127.0.0.1:8000' : '');

  // Safe Image URL helper - converts relative paths from backend to full URL or relative local path
  function resolveImageUrl(path, defaultFallback = 'images/groom-1.webp') {
    if (!path) return defaultFallback;
    // For local images/ paths, prefer relative path so html2canvas / canvas never gets CORS-tainted
    if (path.includes('/images/')) {
      const idx = path.indexOf('/images/');
      return path.substring(idx + 1);
    }
    if (path.startsWith('images/')) return path;
    if (path.startsWith('http://') || path.startsWith('https://')) return path;
    if (path.startsWith('/uploads/') || path.startsWith('uploads/')) {
      const clean = path.startsWith('/') ? path.substring(1) : path;
      return `${BACKEND_URL}/${clean}`;
    }
    return `${BACKEND_URL}/uploads/${path}`;
  }

  // Toast notification UI
  function showToast(message, type = 'info', duration = 4000) {
    let container = document.getElementById('alnikah-toast-container');
    if (!container) {
      container = document.createElement('div');
      container.id = 'alnikah-toast-container';
      container.style.cssText = `
        position: fixed;
        top: 24px;
        right: 24px;
        z-index: 999999;
        display: flex;
        flex-direction: column;
        gap: 12px;
        pointer-events: none;
      `;
      document.body.appendChild(container);
    }

    const toast = document.createElement('div');
    toast.className = `alnikah-toast alnikah-toast-${type}`;
    
    let bgColor = '#6a0b14';
    let borderColor = '#c5a059';
    let icon = '✓';

    if (type === 'success') {
      bgColor = '#1e4620';
      borderColor = '#4caf50';
      icon = '✓';
    } else if (type === 'error') {
      bgColor = '#7b1113';
      borderColor = '#ef5350';
      icon = '✕';
    } else if (type === 'warning') {
      bgColor = '#7c5208';
      borderColor = '#ffa726';
      icon = '⚠';
    }

    toast.style.cssText = `
      min-width: 280px;
      max-width: 420px;
      background: ${bgColor};
      color: #ffffff;
      border: 1.5px solid ${borderColor};
      border-radius: 8px;
      padding: 14px 18px;
      box-shadow: 0 8px 24px rgba(0,0,0,0.3);
      display: flex;
      align-items: center;
      gap: 12px;
      font-family: 'Plus Jakarta Sans', sans-serif;
      font-size: 0.92rem;
      font-weight: 600;
      line-height: 1.4;
      pointer-events: auto;
      opacity: 0;
      transform: translateY(-10px);
      transition: opacity 0.3s ease, transform 0.3s ease;
    `;

    toast.innerHTML = `
      <span style="font-size: 1.1rem; flex-shrink: 0;">${icon}</span>
      <span style="flex-grow: 1;">${message}</span>
      <button style="background: none; border: none; color: #fff; font-size: 1rem; cursor: pointer; padding: 0 4px; opacity: 0.7;">&times;</button>
    `;

    const closeBtn = toast.querySelector('button');
    closeBtn.onclick = () => {
      toast.style.opacity = '0';
      toast.style.transform = 'translateY(-10px)';
      setTimeout(() => toast.remove(), 300);
    };

    container.appendChild(toast);
    requestAnimationFrame(() => {
      toast.style.opacity = '1';
      toast.style.transform = 'translateY(0)';
    });

    setTimeout(() => {
      if (toast.parentElement) {
        toast.style.opacity = '0';
        toast.style.transform = 'translateY(-10px)';
        setTimeout(() => toast.remove(), 300);
      }
    }, duration);
  }

  // Auth User Management
  function getAuthUser() {
    try {
      const raw = localStorage.getItem('alnikah_user');
      return raw ? JSON.parse(raw) : null;
    } catch (e) {
      return null;
    }
  }

  function setAuthUser(user) {
    if (user) {
      localStorage.setItem('alnikah_user', JSON.stringify(user));
      updateNavAuthUI();
    }
  }

  function clearAuthUser() {
    localStorage.removeItem('alnikah_user');
    updateNavAuthUI();
  }

  function isLoggedIn() {
    return !!getAuthUser();
  }

  // Update Navigation Header Auth Buttons dynamically
  function updateNavAuthUI() {
    const authButtons = document.querySelector('.header-auth-buttons');
    if (!authButtons) return;

    const user = getAuthUser();
    if (user) {
      const firstName = (user.name || 'Member').split(' ')[0];
      authButtons.innerHTML = `
        <a href="dashboard.html" class="btn btn-outline btn-sm" id="btn-dashboard">
          <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align: middle; margin-right: 4px;">
            <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
            <circle cx="12" cy="7" r="4"></circle>
          </svg>
          ${firstName}
        </a>
        <button type="button" class="btn btn-burgundy-light btn-sm" id="btn-logout" onclick="AlNikahAPI.logout()">Logout</button>
      `;
    } else {
      authButtons.innerHTML = `
        <a href="login.html" class="btn btn-outline btn-sm" id="btn-login">Login</a>
        <a href="signup.html" class="btn btn-burgundy-light btn-sm" id="btn-signup">Sign Up</a>
      `;
    }
  }

  // Logout method
  async function logout() {
    try {
      await fetch(`${API_BASE}/logout`, {
        method: 'POST',
        headers: { 'Accept': 'application/json' },
        credentials: 'include',
      }).catch(() => {});
    } catch (e) {}
    clearAuthUser();
    showToast('You have been logged out successfully.', 'info');
    setTimeout(() => {
      window.location.href = 'login.html';
    }, 800);
  }

  // Core Request Wrapper
  async function request(endpoint, options = {}) {
    const url = endpoint.startsWith('http') ? endpoint : `${API_BASE}${endpoint.startsWith('/') ? '' : '/'}${endpoint}`;
    
    const defaultHeaders = {
      'Accept': 'application/json',
    };

    if (!(options.body instanceof FormData)) {
      defaultHeaders['Content-Type'] = 'application/json';
    }

    options.headers = {
      ...defaultHeaders,
      ...(options.headers || {})
    };

    // Ensure session cookies are sent and received cross-origin
    options.credentials = options.credentials || 'include';

    try {
      const response = await fetch(url, options);
      const data = await response.json().catch(() => ({ success: false, message: 'Invalid response from server' }));
      
      if (!response.ok) {
        const error = new Error(data.message || `Request failed with status ${response.status}`);
        error.data = data;
        error.status = response.status;
        throw error;
      }
      return data;
    } catch (error) {
      throw error;
    }
  }

  // Auto-init on page load and dynamic layout load
  document.addEventListener('DOMContentLoaded', updateNavAuthUI);
  document.addEventListener('headerLoaded', updateNavAuthUI);
  document.addEventListener('layoutLoaded', updateNavAuthUI);

  // Public API methods
  return {
    API_BASE,
    BACKEND_URL,
    resolveImageUrl,
    showToast,
    getAuthUser,
    setAuthUser,
    clearAuthUser,
    isLoggedIn,
    logout,
    updateNavAuthUI,

    // Auth endpoints
    login: async (credentials) => {
      const res = await request('/login', {
        method: 'POST',
        body: JSON.stringify(credentials),
      });
      if (res.user) {
        setAuthUser(res.user);
      }
      return res;
    },

    signup: async (formData) => {
      const isFormData = formData instanceof FormData;
      const res = await request('/signup', {
        method: 'POST',
        body: isFormData ? formData : JSON.stringify(formData),
      });
      if (res.user) {
        setAuthUser(res.user);
      }
      return res;
    },

    // OTP Endpoints (Signup Verification)
    sendOtp: async (mobile) => {
      return await request('/send-otp', {
        method: 'POST',
        body: JSON.stringify({ mobile }),
      });
    },

    verifyOtp: async (mobile, otp) => {
      return await request('/verify-otp', {
        method: 'POST',
        body: JSON.stringify({ mobile, otp }),
      });
    },

    me: async () => {
      const res = await request('/me');
      if (res.user && res.role === 'user') {
        setAuthUser(res.user);
      }
      return res;
    },

    // Profile Management
    updateProfile: async (profileData) => {
      const isFormData = profileData instanceof FormData;
      const res = await request('/profile/update', {
        method: 'PUT',
        body: isFormData ? profileData : JSON.stringify(profileData),
      });
      if (res.data) {
        setAuthUser(res.data);
      }
      return res;
    },

    changePassword: async (currentPassword, newPassword, newPasswordConfirmation = null) => {
      return await request('/profile/change-password', {
        method: 'POST',
        body: JSON.stringify({
          current_password: currentPassword,
          new_password: newPassword,
          new_password_confirmation: newPasswordConfirmation || newPassword,
        }),
      });
    },

    // Metadata Options (Religions, Sects, Castes)
    getMetaOptions: async () => {
      return await request('/meta/options');
    },

    // Profiles
    getProfiles: async (params = {}) => {
      const query = new URLSearchParams(params).toString();
      return await request(`/profiles${query ? '?' + query : ''}`);
    },

    getFeaturedProfiles: async () => {
      return await request('/profiles/featured');
    },

    getProfile: async (id) => {
      return await request(`/profiles/${id}`);
    },

    // Packages
    getPackages: async () => {
      return await request('/packages');
    },

    getCurrentPackage: async () => {
      return await request('/packages/current');
    },

    purchasePackage: async (packageData) => {
      return await request('/packages/purchase', {
        method: 'POST',
        body: JSON.stringify(packageData),
      });
    },

    // Banners & Gallery
    getBanners: async () => {
      return await request('/banners');
    },

    getGallery: async () => {
      return await request('/gallery');
    },

    // Feedback & Inquiries
    submitContact: async (data) => {
      return await request('/feedback', {
        method: 'POST',
        body: data instanceof FormData ? data : JSON.stringify(data),
      });
    },
  };
})();
