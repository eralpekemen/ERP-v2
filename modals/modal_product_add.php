<!-- modals/modal_product_add.php -->
<div class="modal fade" id="modalProductAdd" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form id="formProductAdd">
                <div class="modal-header">
                    <h5 class="modal-title">Yeni Ürün Ekle</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Ürün Adı *</label>
                        <input type="text" class="form-control" name="name" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Kategori *</label>
                        <select class="form-select" name="category_id" id="productCategorySelect" required>
                            <option value="">-- Yükleniyor... --</option>
                        </select>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Satış Fiyatı (₺) *</label>
                            <input type="number" step="0.01" class="form-control" name="unit_price" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Stok Miktarı</label>
                            <input type="number" step="0.01" class="form-control" name="stock_quantity" value="0">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                    <button type="submit" class="btn btn-primary">Ürün Ekle</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    // Kategorileri yükle
    async function loadCategoriesForProduct() {
        const select = document.getElementById('productCategorySelect');
        select.innerHTML = '<option value="">-- Yükleniyor... --</option>';

        const data = await postData('admin/product_handler.php', { action: 'categories', csrf: CSRF });
        if (!data || !Array.isArray(data)) {
            select.innerHTML = '<option value="">Kategori yok</option>';
            return;
        }

        let opts = '<option value="">-- Kategori Seç --</option>';
        data.forEach(cat => {
            opts += `<option value="${cat.id}">${cat.name}</option>`;
        });
        select.innerHTML = opts;
    }

    // Modal açıldığında kategorileri yükle
    document.getElementById('modalProductAdd')?.addEventListener('show.bs.modal', () => {
        loadCategoriesForProduct();
    });

    // Form gönder
    document.getElementById('formProductAdd')?.addEventListener('submit', async (e) => {
        e.preventDefault();
        const form = e.target;
        const btn = form.querySelector('button[type="submit"]');
        btn.disabled = true;
        btn.innerHTML = 'Ekleniyor...';

        const formData = new FormData(form);
        formData.append('action', 'add');
        formData.append('csrf', CSRF);

        try {
            const res = await fetch('admin/product_handler.php', {
                method: 'POST',
                body: formData
            });
            const data = await res.json();

            if (data.success) {
                alert('Ürün eklendi!');
                bootstrap.Modal.getInstance(form.closest('.modal')).hide();
                form.reset();
                loadProducts(); // tabloyu yenile
            } else {
                alert('Hata: ' + (data.message || 'Bilinmeyen hata'));
            }
        } catch (err) {
            console.error(err);
            alert('Sunucu hatası!');
        } finally {
            btn.disabled = false;
            btn.innerHTML = 'Ürün Ekle';
        }
    });
</script>