// assets/js/m_management.js — %100 ÇALIŞAN, TEMİZ, GÜNCEL SON HALİ
let currentPage = 1;
const perPage = 10;
let allProducts = [];
let categories = [];
let orderCart = [];
let currentProductId = 0;

// CSRF TOKEN PHP'DEN GELECEK, BURADA TANIMLAMA YOK!
let csrfToken = window.csrfToken || ''; // PHP'den gelecek

// TOAST FONKSİYONU
function showToast(message, type = 'danger') {
    $('.toast-container').remove();
    const toast = $(`
        <div class="toast align-items-center text-white bg-${type} border-0" role="alert">
            <div class="d-flex">
                <div class="toast-body">${message}</div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
            </div>
        </div>
    `);
    $('body').append('<div class="toast-container position-fixed top-0 end-0 p-3"></div>');
    $('.toast-container').append(toast);
    new bootstrap.Toast(toast[0]).show();
}

// ÜRÜNLERİ YÜKLE
function loadProducts() {
    console.log("Ürünler yükleniyor...");
    $('#productsBody').html('<tr><td colspan="8" class="text-center py-5"><div class="spinner-border"></div><br>Yükleniyor...</td></tr>');
    $.post('menu_management.php', { ajax: 1, action: 'list' }, function(data) {
        if (data.success && data.products) {
            allProducts = data.products;
            loadCategories();
            renderProducts();
            $('#paginationInfo').html(`Toplam: <strong>${allProducts.length}</strong> ürün`);
        } else {
            $('#productsBody').html('<tr><td colspan="8" class="text-center text-warning">Hiç ürün yok</td></tr>');
        }
    }, 'json').fail(() => {
        showToast('Sunucu hatası!', 'danger');
    });
}

function loadCategories() {
    $.post('menu_management.php', { ajax: 1, action: 'categories' }, function(res) {
        if (res.success) {
            categories = res.categories;
            ['addCategorySelect', 'editCategorySelect'].forEach(id => {
                const select = document.getElementById(id);
                if (select) {
                    select.innerHTML = '<option value="">Kategori Seç</option>';
                    categories.forEach(c => select.innerHTML += `<option value="${c.id}">${c.name}</option>`);
                }
            });
        }
    }, 'json');
}

function renderProducts() {
    const search = $('#searchProducts').val().toLowerCase();
    const filtered = allProducts.filter(p => 
        p.name.toLowerCase().includes(search) || 
        (p.barcode && p.barcode.includes(search))
    );
    const total = filtered.length;
    const pages = Math.ceil(total / perPage);
    const start = (currentPage - 1) * perPage;
    const pageData = filtered.slice(start, start + perPage);

    let tbody = '';
    if (pageData.length === 0) {
        tbody = '<tr><td colspan="8" class="text-center text-muted">Ürün bulunamadı</td></tr>';
    } else {
        pageData.forEach((p, i) => {
            const status = p.status === 'active' 
                ? '<span class="badge bg-success">Satışta</span>' 
                : '<span class="badge bg-danger">Satış Dışı</span>';
            tbody += `<tr>
                <td>${start + i + 1}</td>
                <td><strong>${p.name}</strong></td>
                <td>${p.barcode || '-'}</td>
                <td>${p.category || '-'}</td>
                <td>${parseFloat(p.stock || 0).toFixed(2)}</td>
                <td>${parseFloat(p.price || p.unit_price).toFixed(2)} ₺</td>
                <td>${status}</td>
                <td>
                    <button class="btn btn-info btn-sm" onclick="viewProduct(${p.id})"><i class="fa fa-eye"></i></button>
                    <button class="btn btn-warning btn-sm" onclick="editProduct(${p.id})"><i class="fa fa-pen"></i></button>
                    <button class="btn btn-success btn-sm" onclick="orderProduct(${p.id})"><i class="fa fa-truck"></i></button>
                    <button class="btn btn-danger btn-sm" onclick="deleteProduct(${p.id}, '${p.name.replace(/'/g, "\\'")}')"><i class="fa fa-trash"></i></button>
                </td>
            </tr>`;
        });
    }
    $('#productsBody').html(tbody);

    // PAGINATION
    let pag = '';
    for (let i = 1; i <= pages; i++) {
        pag += `<li class="page-item ${i === currentPage ? 'active' : ''}"><a class="page-link" href="#" onclick="currentPage=${i}; renderProducts(); return false;">${i}</a></li>`;
    }
    $('#pagination').html(pag || '<li class="page-item active"><span class="page-link">1</span></li>');
}

// DETAY MODALI — %100 ÇALIŞIYOR!
function viewProduct(id) {
    currentProductId = id;
    $('#viewProductModal').data('product-id', id);

    $.post('menu_management.php', {
        ajax: 1,
        action: 'get_product_detail',
        product_id: id,
        csrf_token: csrfToken
    }, function(res) {
        if (!res.success || !res.product) return showToast('Ürün bulunamadı!', 'danger');

        const p = res.product;
        const extras = res.extras || [];
        const recipe = res.recipe || [];

        $('#viewProductName').text(p.name);
        $('#viewProductPrice').text(parseFloat(p.unit_price).toFixed(2) + ' ₺');
        $('#viewProductStock').text(parseFloat(p.stock_quantity || 0).toFixed(2));
        $('#viewProductBarcode').text(p.barcode || 'Yok');

        // KAR MARJI
        let totalCost = 0;
        recipe.forEach(r => totalCost += parseFloat(r.cost || 0) * parseFloat(r.quantity || 0));
        const profitPercent = p.unit_price > totalCost ? ((p.unit_price - totalCost) / p.unit_price) * 100 : 0;
        const badge = profitPercent >= 50 ? 'bg-success' : profitPercent >= 30 ? 'bg-warning' : 'bg-danger';

        $('#profitInfo').html(`
            <div class="text-center mt-4">
                <strong>Kar Marjı: </strong>
                <span class="badge ${badge} fs-5">${profitPercent.toFixed(1)}%</span>
            </div>
        `);

        loadExtrasToTab(extras);
        loadRecipeToTab(recipe, p.unit_price);
        $('#viewProductModal').modal('show');
    }, 'json');
}

function loadExtrasToTab(extras) {
    const el = document.getElementById('extraFeatures');
    if (!el) return;
    if (!extras.length) {
        el.innerHTML = '<p class="text-muted text-center">Ekstra malzeme yok</p>';
        return;
    }
    let html = '<div class="row">';
    extras.forEach(e => {
        html += `<div class="col-6 mb-3"><div class="border rounded p-3 bg-light"><strong>${e.name}</strong> <span class="text-success float-end">+${parseFloat(e.price).toFixed(2)} ₺</span></div></div>`;
    });
    html += '</div>';
    el.innerHTML = html;
}

function loadRecipeToTab(recipe, salePrice) {
    const el = document.getElementById('productRecipe');
    if (!el) return;
    if (!recipe.length) {
        el.innerHTML = '<p class="text-center text-muted">Reçete tanımlanmamış</p>';
        return;
    }
    let totalCost = 0;
    let rows = '';
    recipe.forEach(r => {
        const line = parseFloat(r.cost || 0) * parseFloat(r.quantity || 0);
        totalCost += line;
        rows += `<tr><td>${r.ingredient_name}</td><td>${parseFloat(r.quantity).toFixed(3)}</td><td>${parseFloat(r.cost).toFixed(2)} ₺</td><td class="text-end">${line.toFixed(2)} ₺</td></tr>`;
    });
    const profit = salePrice - totalCost;
    el.innerHTML = `
        <div class="table-responsive">
            <table class="table table-sm table-bordered">
                <thead class="table-light"><tr><th>Malzeme</th><th>Miktar</th><th>Fiyat</th><th>Tutar</th></tr></thead>
                <tbody>${rows}</tbody>
                <tfoot class="table-success fw-bold">
                    <tr><td colspan="3" class="text-end">Toplam Maliyet:</td><td class="text-end">${totalCost.toFixed(2)} ₺</td></tr>
                    <tr><td colspan="3" class="text-end">Kar:</td><td class="text-end text-success">${profit.toFixed(2)} ₺</td></tr>
                </tfoot>
            </table>
        </div>
    `;
}

// SAYFA YÜKLENDİĞİNDE
$(document).ready(function() {
    loadProducts();
});

// EKSTRA EKLEME MODALI
function openAddExtraModal() {
    $.post('menu_management.php', { ajax: 1, action: 'get_ingredients', csrf_token: csrfToken }, function(res) {
        if (res.success) {
            const select = $('#ingredientSelect');
            select.empty().append('<option value="">— Malzeme Seç —</option>');
            res.ingredients.forEach(i => select.append(`<option value="${i.id}">${i.name}</option>`));
        }
        $('#addExtraModal').modal('show');
    }, 'json');
}