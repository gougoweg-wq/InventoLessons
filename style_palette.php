<?php /* Global earthy minimalist style palette */ ?>
<style>
body {
  font-family: 'Montserrat', sans-serif;
  background-color: #EBE3D5;
  margin: 0;
  color: #4a4036;
}

.navbar {
  background: #B0A695 !important;
  border-radius: 12px;
  margin-bottom: 20px;
  box-shadow: 0 4px 10px rgba(0,0,0,0.15);
}
.navbar-brand { font-weight: 600; color: #F3EEEA !important; }

.card, .profile-card {
  background: #F3EEEA;
  border-radius: 16px;
  box-shadow: 0 6px 18px rgba(0,0,0,0.1);
  padding: 28px;
}
.table { background: #F3EEEA; border-radius: 12px; overflow: hidden; }
.table thead { background: #B0A695; color: #F3EEEA; }
.table-hover tbody tr:hover { background: #EBE3D5; }

.avatar-lg {
  width: 140px; height: 140px; border-radius: 50%;
  border: 4px solid #B0A695; object-fit: cover; margin-bottom: 10px;
}
.profile-avatar {
  width: 40px; height: 40px; border-radius: 50%;
  object-fit: cover; border: none; background: none;
  box-shadow: 0 0 6px rgba(0,0,0,0.1);
}

.btn-primary {
  background: #B0A695; border: none; color: #F3EEEA;
  border-radius: 10px; transition: all 0.25s ease;
}
.btn-primary:hover { background: #968b7c; transform: scale(1.05); }

.btn-cancel {
  background: #776B5D; color: #F3EEEA;
  border: none; border-radius: 8px; padding: 6px 12px; transition: 0.3s;
}
.btn-cancel:hover { background: #5f554b; }

.dropdown-menu {
  border-radius: 12px; background: #F3EEEA;
  box-shadow: 0 4px 10px rgba(0,0,0,0.1);
}
.dropdown-item:hover { background: #EBE3D5; }

.modal-content { background-color: #EBE3D5; color: #4a4036; border-radius: 12px; }
.modal-header { background: #B0A695; color: #F3EEEA; border: none; }
.modal-body { background: #F3EEEA; }
.zoom-btn {
  background: #B0A695; color: #F3EEEA;
  border: none; border-radius: 50%; width: 45px; height: 45px; font-size: 22px;
}
.zoom-btn:hover { background: #968b7c; }

.alert-success {
  background: #EBE3D5; border: 2px solid #B0A695; color: #4a4036;
}
.alert-danger {
  background: #F3EEEA; border: 2px solid #776B5D; color: #6d5f53;
}

footer {
  background: #B0A695; color: #F3EEEA;
  border-radius: 10px; margin-top: 40px;
  padding: 12px; font-size: 14px; text-align: center;
  box-shadow: 0 -4px 10px rgba(0,0,0,0.1);
}
</style>
