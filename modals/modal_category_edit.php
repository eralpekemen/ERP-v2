<!-- modals/modal_category_edit.php -->
<div class="modal fade" id="modalCategoryEdit" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <form id="formCategoryEdit">
                <input type="hidden" name="id">
                <div class="modal-header">
                    <h5 class="modal-title">Kategoriyi Düzenle</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Kategori Adı *</label>
                        <input type="text" class="form-control" name="name" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                    <button type="submit" class="btn btn-primary">Kaydet</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    // Modal açıldığında veri doldur
    document.getElementById('modalCategoryEdit')?.addEventListener('show.bs.modal', (e) => {
        const button = e.relatedTarget;
        const id = button.getAttribute('data-id');
        const name = button.getAttribute('data-name');

        const form = document.getElementById('formCategoryEdit');
        form.querySelector('[name="id"]').value = id;
        form.querySelector('[name="name"]').value = name;
    });

    // Form gönder
    document.getElementById('formCategoryEdit')?.addEventListener('submit', async (e) => {
        e.preventDefault();
        const form = e.target;
        const btn = form.querySelector('button[type="submit"]');
        const modal = form.closest('.modal');

        btn.disabled = true;
        btn.innerHTML = 'Kaydediliyor...';

        const formData = new FormData(form);
        formData.append('action', 'edit_category');
        formData.append('csrf', CSRF);

        try {
            const res = await fetch('admin/product_handler.php', { method: 'POST', body: formData });
            const data = await res.json();

            if (data.success) {
                alert('Kategori güncellendi: ' + data.name);
                bootstrap.Modal.getInstance(modal).hide();
                loadCategoriesForProduct(); // Ürün modalı için yenile
                loadProducts(); // Tabloyu yenile (isim değişti)
            } else {
                alert('Hata: ' + data.message);
            }
        } catch (err) {
            alert('Sunucu hatası!');
        } finally {
            btn.disabled = false;
            btn.innerHTML = 'Kaydet';
        }
    });
</script>