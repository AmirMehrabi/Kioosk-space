<label for="new-password">گذرواژه</label>
<input id="new-password" name="password" type="password" dir="ltr" autocomplete="new-password" minlength="12" aria-describedby="password-hint" required @error('password') aria-invalid="true" @enderror>
<small id="password-hint">دست‌کم ۱۲ نویسه</small>
<label for="password-confirmation">تکرار گذرواژه</label>
<input id="password-confirmation" name="password_confirmation" type="password" dir="ltr" autocomplete="new-password" minlength="12" required>
