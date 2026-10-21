/**
 * Al-Nikah Matrimonial Services - Headless API Client
 * Vanilla JavaScript Integration for pure HTML/CSS/JS Frontend
 */
const AlNikahAPI = (function () {
  // Auto-detect API base URL matching browser hostname (localhost or 127.0.0.1)
  const isLocalHost = window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1' || window.location.protocol === 'file:' || !window.location.hostname;
  const currentHost = (window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1') ? window.location.hostname : '127.0.0.1';
  const API_BASE = window.ALNIKAH_API_URL || (isLocalHost ? `http://${currentHost}:8000/api` : '/api');
  const BACKEND_URL = window.ALNIKAH_BACKEND_URL || (isLocalHost ? `http://${currentHost}:8000` : '');

  // Neutral Silhouette Avatar Data URI (Brand themed: Burgundy & Gold silhouette, NO dummy bride/groom)
  const NEUTRAL_AVATAR = 'data:image/svg+xml;utf8,' + encodeURIComponent(
    '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 120 120" width="120" height="120">' +
    '<circle cx="60" cy="60" r="58" fill="#faf4eb" stroke="#c5a059" stroke-width="3"/>' +
    '<circle cx="60" cy="46" r="22" fill="#8c1d28"/>' +
    '<path d="M26 102 C26 78, 42 68, 60 68 C78 68, 94 102 Z" fill="#8c1d28"/>' +
    '</svg>'
  );

  // Safe Image URL helper - converts relative paths from backend to full URL or relative local path
  function resolveImageUrl(path, defaultFallback = NEUTRAL_AVATAR) {
    if (!path) return defaultFallback;
    if (typeof path !== 'string') return defaultFallback;
    path = path.trim();
    if (!path || path === 'undefined' || path === 'null' || path === 'none') return defaultFallback;

    // 1. Data URI (Base64)
    if (path.startsWith('data:image/')) return path;

    // 2. Full URL (HTTP / HTTPS)
    if (path.startsWith('http://') || path.startsWith('https://')) {
      if (isLocalHost && (path.includes('alnikahmarriage.com/storage/users/') || path.includes('alnikahmarriage.com/uploads/'))) {
        const filename = path.split('/').pop();
        if (filename) return `${BACKEND_URL}/api/photo/${encodeURIComponent(filename)}`;
      }
      return path;
    }

    // 3. For local frontend images/ paths
    if (path.includes('/images/')) {
      const idx = path.indexOf('/images/');
      return path.substring(idx + 1);
    }
    if (path.startsWith('images/')) return path;

    // 4. Clean leading slash
    const clean = path.startsWith('/') ? path.substring(1) : path;

    // 5. Explicit storage paths
    if (clean.startsWith('storage/users/')) {
      return `${BACKEND_URL}/${clean}`;
    }
    if (clean.startsWith('storage/')) {
      return `${BACKEND_URL}/${clean}`;
    }

    // 6. Explicit uploads path
    if (clean.startsWith('uploads/')) {
      return `${BACKEND_URL}/${clean}`;
    }

    // 7. API Photo Stream path
    if (clean.startsWith('api/photo/')) {
      return `${BACKEND_URL}/${clean}`;
    }

    // 8. Raw filename from database (e.g. 1775541947-profile_pic-NAZIYA 24.jpg)
    // Stream through /api/photo which searches storage/app/public/users, public/storage/users, uploads, etc.
    return `${BACKEND_URL}/api/photo/${encodeURIComponent(clean)}`;
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

    getAuthProfile: async () => {
      const res = await request('/profile');
      if (res && res.success && res.data) {
        setAuthUser(res.data);
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

    uploadProfilePhoto: async (file) => {
      const formData = new FormData();
      formData.append('photo', file);
      const res = await request('/profile/photo', {
        method: 'POST',
        body: formData,
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

    // UI Card Template Renderers
    renderProfileCard: (p) => {
      const isGroom = (p.user_type || '').toUpperCase() === 'GROOM' || p.gender === 'Groom';
      const badgeClass = isGroom ? 'badge-groom' : 'badge-bride';
      const gender = isGroom ? 'Groom' : 'Bride';
      const image = resolveImageUrl(p.image, NEUTRAL_AVATAR);
      const fallbackImg = NEUTRAL_AVATAR;

      return `
        <article class="profile-card profile-card-custom" data-gender="${gender}" data-firqa="${p.firqa || ''}" data-maslak="${p.maslak || ''}" data-caste="${p.caste || ''}" data-status="${p.status || ''}" data-city="${p.city || ''}">
          <span class="corner-bracket corner-tl" aria-hidden="true"></span>
          <span class="corner-bracket corner-br" aria-hidden="true"></span>
          <div class="profile-card-top">
            <a href="profile-detail.html?id=${p.id}" class="profile-avatar-ring" aria-label="View ${gender} Profile ${p.profile_id}">
              <img src="${image}" alt="${gender} Profile" class="profile-avatar-img" width="130" height="130" loading="lazy" onerror="this.onerror=null; this.src='${fallbackImg}';" />
            </a>
            <span class="profile-gender-badge ${badgeClass}">${gender}</span>
          </div>
          <div class="profile-card-bottom">
            <div class="profile-id-strip" style="text-align: center; font-weight: 700; color: #6a0b14; font-size: 0.85rem; padding: 4px 0;">ID: ${p.profile_id}</div>
            <div class="profile-info-content">
              <h3 class="profile-edu-title">${p.education || 'Graduate'}</h3>
              <p class="profile-profession">${p.profession || 'Business / Service'}</p>
              <ul class="profile-fields-list">
                <li><span class="p-field-lbl">Age / DOB:</span><span class="p-field-val">${p.age_dob || '25 Years'}</span></li>
                <li><span class="p-field-lbl">Firqa:</span><span class="p-field-val">${p.firqa || 'Sunni'}</span></li>
                <li><span class="p-field-lbl">Maslak:</span><span class="p-field-val">${p.maslak || 'NO BAR'}</span></li>
                <li><span class="p-field-lbl">Caste:</span><span class="p-field-val">${p.caste || 'Khan / Shaikh'}</span></li>
                <li><span class="p-field-lbl">Status:</span><span class="p-field-val">${p.status || 'Unmarried'}</span></li>
                <li><span class="p-field-lbl">City:</span><span class="p-field-val">${p.city || 'Lucknow'}</span></li>
              </ul>
            </div>
            <div class="dash-card-actions-row">
              <button type="button" class="btn-dash-card-interest" onclick="AlNikahAPI.handleSendInterest('${p.id}', '${p.name || p.profile_id}', this)">
                <svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor">
                  <path d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z"/>
                </svg>
                Send Interest
              </button>
              <a href="profile-detail.html?id=${p.id}" class="btn btn-card-profile">View Profile</a>
            </div>
          </div>
        </article>
      `;
    },

    renderPackageCard: (pkg) => {
      const hasDiscount = pkg.original_price > pkg.offer_price;
      const features = Array.isArray(pkg.features) ? pkg.features : [];
      const isAuth = !!getAuthUser();

      return `
        <div class="package-card ${pkg.card_class || 'package-card'}" style="min-width: 280px;">
          <div class="package-ribbon">${pkg.ribbon || 'Membership Plan'}</div>
          <div class="package-card-header">
            <h3 class="package-name">${pkg.name}</h3>
            ${hasDiscount ? `
            <div class="pkg-old-price-line">
              <span>Our Price:</span> <del>${pkg.original_price_formatted}</del>
            </div>` : ''}
            <div class="package-price-wrap">
              <span class="pkg-offer-label">Offer Price</span>
              <span class="package-currency">₹</span>
              <span class="package-amount">${pkg.offer_price_formatted}</span>
              <span class="package-period">INR</span>
            </div>
            <span class="package-duration">${pkg.duration || '1 MONTH'}</span>
          </div>
          <div class="package-card-body">
            <ul class="package-features-list">
              ${features.map(f => `
                <li>
                  <span class="pkg-check" aria-hidden="true">&#10003;</span>
                  ${f}
                </li>
              `).join('')}
            </ul>
          </div>
          <div class="package-card-footer">
            <a href="${isAuth ? 'dashboard.html' : 'login.html'}" class="btn btn-burgundy btn-block select-plan-btn">Select Plan</a>
          </div>
        </div>
      `;
    },

    // Interactive Interest Action Handler
    handleSendInterest: (profileId, name, btnEl) => {
      if (!isLoggedIn()) {
        showToast('Please login to send an express interest.', 'warning');
        setTimeout(() => {
          window.location.href = 'login.html';
        }, 1200);
        return;
      }
      if (btnEl) {
        btnEl.disabled = true;
        btnEl.innerHTML = '<span>✓ Interest Sent</span>';
        btnEl.style.background = '#1e4620';
        btnEl.style.color = '#ffffff';
      }
      showToast(`Alhamdulillah! Express Interest successfully sent to ${name}.`, 'success');
    },

    // Dynamic Homepage Data Initializer
    loadHomePageData: async () => {
      // 1. Load Real Membership Packages
      try {
        const pkgTrack = document.getElementById('packages-slider-track');
        if (pkgTrack) {
          const res = await AlNikahAPI.getPackages();
          if (res.success && Array.isArray(res.data) && res.data.length > 0) {
            pkgTrack.innerHTML = res.data.map(pkg => AlNikahAPI.renderPackageCard(pkg)).join('');
            document.dispatchEvent(new Event('packagesLoaded'));
          }
        }
      } catch (err) {
        console.warn('Could not load live packages:', err);
      }

      // 2. Load Real Bride Profiles
      try {
        const brideTrack = document.getElementById('brides-slider-track');
        if (brideTrack) {
          const res = await AlNikahAPI.getProfiles({ type: 'BRIDE', limit: 10 });
          if (res.success && Array.isArray(res.data) && res.data.length > 0) {
            brideTrack.innerHTML = res.data.map(p => AlNikahAPI.renderProfileCard(p)).join('');
            document.dispatchEvent(new Event('bridesLoaded'));
          }
        }
      } catch (err) {
        console.warn('Could not load live bride profiles:', err);
      }

      // 3. Load Real Groom Profiles
      try {
        const groomTrack = document.getElementById('grooms-slider-track');
        if (groomTrack) {
          const res = await AlNikahAPI.getProfiles({ type: 'GROOM', limit: 10 });
          if (res.success && Array.isArray(res.data) && res.data.length > 0) {
            groomTrack.innerHTML = res.data.map(p => AlNikahAPI.renderProfileCard(p)).join('');
            document.dispatchEvent(new Event('groomsLoaded'));
          }
        }
      } catch (err) {
        console.warn('Could not load live groom profiles:', err);
      }

      // Synchronize carousel controls after dynamic DOM insertion
      setTimeout(() => {
        window.dispatchEvent(new Event('resize'));
      }, 50);

      // 4. Load Active Metadata Options into Quick Search
      try {
        const firqaSelect = document.getElementById('search-firqa') || document.querySelector('select[name="firqa"]');
        const casteSelect = document.getElementById('search-caste') || document.querySelector('select[name="caste"]');
        if (firqaSelect || casteSelect) {
          const res = await AlNikahAPI.getMetaOptions();
          if (res.success && res.data) {
            if (firqaSelect && Array.isArray(res.data.religions)) {
              firqaSelect.innerHTML = '<option value="">All Sects / Firqa</option>' +
                res.data.religions.map(r => `<option value="${r.name}">${r.name}</option>`).join('');
            }
            if (casteSelect && Array.isArray(res.data.castes)) {
              casteSelect.innerHTML = '<option value="">All Castes / Biradari</option>' +
                res.data.castes.map(c => `<option value="${c.name}">${c.name}</option>`).join('');
            }
          }
        }
      } catch (err) {
        console.warn('Could not load meta options:', err);
      }
    },

    // Dynamic Member Dashboard Initializer
    loadDashboardData: async () => {
      // 1. Check & verify authentication state with backend
      let currentUser = getAuthUser();
      try {
        const meRes = await AlNikahAPI.me();
        if (meRes.success && meRes.user) {
          currentUser = meRes.user;
          setAuthUser(currentUser);
        } else if (!currentUser) {
          clearAuthUser();
          window.location.href = 'login.html';
          return;
        }
      } catch (e) {
        if (!currentUser) {
          clearAuthUser();
          window.location.href = 'login.html';
          return;
        }
      }

      // Fetch detailed standardized profile if available
      try {
        const profileRes = await AlNikahAPI.getAuthProfile();
        if (profileRes && profileRes.success && profileRes.data) {
          currentUser = { ...currentUser, ...profileRes.data };
          setAuthUser(currentUser);
        }
      } catch (e) {
        console.warn('Could not fetch detailed auth profile:', e);
      }

      // 2. Populate Real User Topbar, Avatar & Welcome Banner
      const userName = currentUser.name || 'Respected Member';
      const isGroom = (currentUser.user_type || '').toUpperCase() === 'GROOM' || (currentUser.gender || '').toLowerCase() === 'groom';
      const userProfileId = currentUser.profile_id || (isGroom ? `ALN-G-${currentUser.user_id || currentUser.id}` : `ALN-B-${currentUser.user_id || currentUser.id}`);

      // Determine real dynamic avatar (priority: base64 -> photo_stream_url -> image -> profile_pic -> neutral avatar)
      let userAvatar = '';
      if (currentUser.image_base64) {
        userAvatar = currentUser.image_base64;
      } else if (currentUser.photo_stream_url) {
        userAvatar = currentUser.photo_stream_url;
      } else if (currentUser.image) {
        userAvatar = resolveImageUrl(currentUser.image, NEUTRAL_AVATAR);
      } else if (currentUser.profile_pic) {
        userAvatar = resolveImageUrl(currentUser.profile_pic, NEUTRAL_AVATAR);
      } else if (Array.isArray(currentUser.images) && currentUser.images.length > 0) {
        userAvatar = resolveImageUrl(currentUser.images[0], NEUTRAL_AVATAR);
      } else {
        userAvatar = NEUTRAL_AVATAR;
      }

      // Topbar user summary
      const topbarNameEl = document.getElementById('topbar-user-name') || document.querySelector('.topbar-profile-summary span[style*="font-weight: 700"]');
      if (topbarNameEl) topbarNameEl.textContent = userName;

      const topbarIdEl = document.getElementById('topbar-user-id') || document.querySelector('.topbar-profile-summary span[style*="font-size: 0.78rem"]');
      if (topbarIdEl) topbarIdEl.textContent = `ID: ${userProfileId}`;

      const topbarAvatarEl = document.getElementById('topbar-user-avatar') || document.querySelector('.topbar-avatar');
      if (topbarAvatarEl) {
        topbarAvatarEl.src = userAvatar;
        topbarAvatarEl.onerror = () => {
          topbarAvatarEl.onerror = null;
          topbarAvatarEl.src = NEUTRAL_AVATAR;
        };
      }

      // Welcome banner
      const welcomeH1 = document.querySelector('.welcome-text-side h1');
      if (welcomeH1) welcomeH1.textContent = `Welcome Back, ${userName}`;

      const welcomeSubP = document.querySelector('.welcome-text-side p');
      const oppositeType = isGroom ? 'BRIDE' : 'GROOM';
      if (welcomeSubP) {
        welcomeSubP.textContent = `Your profile is actively matching with verified ${oppositeType === 'GROOM' ? 'Groom' : 'Bride'} candidates in Lucknow & nearby.`;
      }

      // 3. Populate Recommended Matches (opposite gender)
      try {
        const dashSliderTrack = document.getElementById('dash-profiles-track');
        if (dashSliderTrack) {
          const res = await AlNikahAPI.getProfiles({ type: oppositeType, limit: 6 });
          if (res.success && Array.isArray(res.data) && res.data.length > 0) {
            dashSliderTrack.innerHTML = res.data.map(p => AlNikahAPI.renderProfileCard(p)).join('');
            if (typeof updateDashSlider === 'function') updateDashSlider(0);
          }
        }
      } catch (err) {
        console.warn('Could not load recommended matches:', err);
      }

      // 4. Populate All Matches Grid
      try {
        const matchesGrid = document.getElementById('matches-profiles-grid') || document.querySelector('.matches-card-grid');
        if (matchesGrid) {
          const res = await AlNikahAPI.getProfiles({ type: oppositeType, limit: 12 });
          if (res.success && Array.isArray(res.data) && res.data.length > 0) {
            matchesGrid.innerHTML = res.data.map(p => AlNikahAPI.renderProfileCard(p)).join('');
          }
        }
      } catch (err) {
        console.warn('Could not load verified matches grid:', err);
      }

      // 5. Populate Real Packages & User Active Plan in Dashboard
      try {
        const dashPlansWrap = document.getElementById('tab-plans');
        const planListContainer = document.getElementById('dash-plans-grid') || (dashPlansWrap ? dashPlansWrap.querySelector('.dash-plans-grid') || dashPlansWrap.querySelector('.packages-grid') : null);
        if (planListContainer) {
          const plansRes = await AlNikahAPI.getPackages();
          if (plansRes.success && Array.isArray(plansRes.data)) {
            planListContainer.innerHTML = plansRes.data.map(pkg => AlNikahAPI.renderPackageCard(pkg)).join('');
          }
        }
        // Update active plan badge
        const activePlanBadge = document.getElementById('dash-active-plan-badge');
        if (activePlanBadge) {
          activePlanBadge.textContent = `Current: ${currentUser.package || 'FREE REGISTRATION'}`;
        }
      } catch (err) {
        console.warn('Could not load dashboard packages:', err);
      }

      // 6. Prefill Edit Profile Form with Real Database Values
      try {
        const editSection = document.getElementById('tab-profile');
        if (editSection) {
          // Update profile card in Tab 3
          const editAvatarEl = document.getElementById('edit-profile-avatar');
          if (editAvatarEl) {
            editAvatarEl.src = userAvatar;
            editAvatarEl.onerror = () => {
              editAvatarEl.onerror = null;
              editAvatarEl.src = NEUTRAL_AVATAR;
            };
          }
          const editNameEl = document.getElementById('edit-profile-name');
          if (editNameEl) editNameEl.textContent = userName;

          const editIdEl = document.getElementById('edit-profile-id');
          if (editIdEl) editIdEl.textContent = `ID: ${userProfileId} • Active`;

          // Helper to set field value
          const setVal = (id, val) => {
            const el = document.getElementById(id);
            if (el && val !== undefined && val !== null && String(val).trim() !== '' && String(val) !== 'No-detail') {
              el.value = val;
            }
          };

          setVal('edit-name', currentUser.name || '');
          setVal('edit-dob', currentUser.age_dob || currentUser.dob || currentUser.age || '');
          setVal('edit-height', currentUser.height || '');
          setVal('edit-firqa', currentUser.firqa || currentUser.sect || '');
          setVal('edit-caste', currentUser.caste || '');
          setVal('edit-qualification', currentUser.education || currentUser.qualification || '');
          setVal('edit-work', currentUser.profession || currentUser.work || '');
          setVal('edit-income', currentUser.income || '');
          setVal('edit-city', currentUser.city || currentUser.n_address || currentUser.address || '');
          setVal('edit-mobile', currentUser.contact_number || currentUser.mobile || '');
          setVal('edit-alt-mobile', currentUser.alternate_mobile || '');
          setVal('edit-about', currentUser.additional_info || '');
          setVal('edit-expectations', currentUser.prefered_location || '');

          // Photo Upload Handling
          const btnChangePhoto = document.getElementById('btn-change-photo');
          const photoInput = document.getElementById('user-photo-file-input');
          const photoStatus = document.getElementById('photo-upload-status');

          if (btnChangePhoto && photoInput) {
            btnChangePhoto.onclick = () => photoInput.click();

            photoInput.onchange = async () => {
              const file = photoInput.files[0];
              if (!file) return;

              if (photoStatus) {
                photoStatus.style.display = 'block';
                photoStatus.style.color = '#786e6b';
                photoStatus.textContent = 'Uploading photo...';
              }
              btnChangePhoto.disabled = true;

              try {
                const uploadRes = await AlNikahAPI.uploadProfilePhoto(file);
                if (uploadRes.success) {
                  const newImg = uploadRes.photo_url || (uploadRes.data && (uploadRes.data.image_base64 || uploadRes.data.photo_stream_url || uploadRes.data.image)) || resolveImageUrl(uploadRes.data?.profile_pic);
                  showToast('Alhamdulillah! Profile photo updated successfully.', 'success');
                  if (editAvatarEl && newImg) editAvatarEl.src = newImg;
                  if (topbarAvatarEl && newImg) topbarAvatarEl.src = newImg;
                  if (photoStatus) {
                    photoStatus.style.color = '#1b5e20';
                    photoStatus.textContent = '✓ Photo updated successfully';
                  }
                } else {
                  showToast(uploadRes.message || 'Failed to upload photo.', 'error');
                  if (photoStatus) {
                    photoStatus.style.color = '#b71c1c';
                    photoStatus.textContent = uploadRes.message || 'Upload failed.';
                  }
                }
              } catch (err) {
                showToast(err.message || 'Photo upload error.', 'error');
                if (photoStatus) {
                  photoStatus.style.color = '#b71c1c';
                  photoStatus.textContent = 'Error uploading photo.';
                }
              } finally {
                btnChangePhoto.disabled = false;
              }
            };
          }

          // Save / Update Biodata Handler
          const handleUpdateBiodata = async (triggerBtn) => {
            if (triggerBtn) {
              triggerBtn.disabled = true;
              triggerBtn.dataset.originalText = triggerBtn.textContent;
              triggerBtn.textContent = 'Saving...';
            }

            const payload = {
              name: document.getElementById('edit-name')?.value || '',
              dob: document.getElementById('edit-dob')?.value || '',
              height: document.getElementById('edit-height')?.value || '',
              sect: document.getElementById('edit-firqa')?.value || '',
              caste: document.getElementById('edit-caste')?.value || '',
              qualification: document.getElementById('edit-qualification')?.value || '',
              work: document.getElementById('edit-work')?.value || '',
              income: document.getElementById('edit-income')?.value || '',
              n_address: document.getElementById('edit-city')?.value || '',
              mobile: document.getElementById('edit-mobile')?.value || '',
              alternate_mobile: document.getElementById('edit-alt-mobile')?.value || '',
              additional_info: document.getElementById('edit-about')?.value || '',
              prefered_location: document.getElementById('edit-expectations')?.value || '',
            };

            try {
              const updateRes = await AlNikahAPI.updateProfile(payload);
              if (updateRes.success) {
                showToast('Alhamdulillah! Profile changes saved successfully.', 'success');
                if (updateRes.data && updateRes.data.name) {
                  if (topbarNameEl) topbarNameEl.textContent = updateRes.data.name;
                  if (editNameEl) editNameEl.textContent = updateRes.data.name;
                  if (welcomeH1) welcomeH1.textContent = `Welcome Back, ${updateRes.data.name}`;
                }
              } else {
                showToast(updateRes.message || 'Could not update profile.', 'error');
              }
            } catch (e) {
              showToast(e.message || 'Failed to save profile changes.', 'error');
            } finally {
              if (triggerBtn) {
                triggerBtn.disabled = false;
                triggerBtn.textContent = triggerBtn.dataset.originalText || 'Save Changes';
              }
            }
          };

          const updateBtn = document.getElementById('btn-update-biodata');
          if (updateBtn) updateBtn.onclick = () => handleUpdateBiodata(updateBtn);

          const saveHeaderBtn = document.getElementById('btn-save-biodata-header');
          if (saveHeaderBtn) saveHeaderBtn.onclick = () => handleUpdateBiodata(saveHeaderBtn);
        }
      } catch (err) {
        console.warn('Could not wire edit profile form:', err);
      }

      // 7. Bind Change Password Form to Real API
      try {
        const settingsSection = document.getElementById('tab-settings');
        if (settingsSection) {
          const passInputs = settingsSection.querySelectorAll('input[type="password"]');
          const savePassBtn = settingsSection.querySelector('button[onclick*="Password updated"]');
          if (savePassBtn && passInputs.length >= 2) {
            savePassBtn.removeAttribute('onclick');
            savePassBtn.onclick = async () => {
              const currentPass = passInputs[0].value.trim();
              const newPass = passInputs[1].value.trim();

              if (!currentPass) {
                showToast('Please enter your current password.', 'warning');
                passInputs[0].focus();
                return;
              }
              if (!newPass || newPass.length < 6) {
                showToast('New password must be at least 6 characters.', 'warning');
                passInputs[1].focus();
                return;
              }

              savePassBtn.disabled = true;
              savePassBtn.textContent = 'Saving Password...';

              try {
                const res = await AlNikahAPI.changePassword(currentPass, newPass, newPass);
                if (res.success) {
                  showToast('Alhamdulillah! Password changed successfully.', 'success');
                  passInputs[0].value = '';
                  passInputs[1].value = '';
                } else {
                  showToast(res.message || 'Could not update password.', 'error');
                }
              } catch (err) {
                showToast(err.message || 'Invalid current password.', 'error');
              } finally {
                savePassBtn.disabled = false;
                savePassBtn.textContent = 'Save New Password';
              }
            };
          }
        }
      } catch (err) {
        console.warn('Could not wire password form:', err);
      }
    },

    // Dynamic Profile Detail Page Initializer
    loadProfileDetailPage: async () => {
      const urlParams = new URLSearchParams(window.location.search);
      const profileId = urlParams.get('id') || urlParams.get('profile_id');
      if (!profileId) return;

      try {
        const res = await AlNikahAPI.getProfile(profileId);
        if (res.success && res.data) {
          const p = res.data;
          const isGroom = p.user_type === 'GROOM' || p.gender === 'Groom';

          // Update titles & headings
          document.title = `${p.profile_id} - ${p.gender} Matrimonial Profile | Al-Nikah`;
          const codeEl = document.querySelector('.detail-profile-code');
          if (codeEl) codeEl.textContent = `ID: ${p.profile_id}`;

          const nameEl = document.querySelector('.detail-person-name');
          if (nameEl) nameEl.textContent = p.name || 'Confidential Member';

          const eduTitleEl = document.querySelector('.detail-job-title');
          if (eduTitleEl) eduTitleEl.textContent = `${p.education} • ${p.profession}`;

          // Update photos if slider exists
          const mainImg = document.getElementById('detail-main-img');
          if (mainImg && (p.image || p.photo_stream_url)) {
            mainImg.src = resolveImageUrl(p.photo_stream_url || p.image);
          }

          // Express interest button in detail
          const intBtn = document.querySelector('.btn-detail-interest');
          if (intBtn) {
            intBtn.onclick = () => {
              AlNikahAPI.handleSendInterest(p.id, p.name || p.profile_id, intBtn);
            };
          }
        }
      } catch (e) {
        console.warn('Could not load profile detail:', e);
      }
    },
  };
})();

