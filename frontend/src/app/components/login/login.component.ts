import { Component } from '@angular/core';
import { FormBuilder, FormGroup, Validators } from '@angular/forms';
import { Router } from '@angular/router';
import { AuthService } from '../../services/auth.service';

@Component({
  selector: 'app-login',
  templateUrl: './login.component.html',
  styleUrls: ['./login.component.css']
})
export class LoginComponent {
  loginForm: FormGroup;
  registerForm: FormGroup;
  isLoginMode = true;
  error = '';
  successMessage = '';
  apis = ['Chat4All Local', 'WhatsApp (Coming Soon)', 'Telegram (Coming Soon)'];
  selectedApi = 'Chat4All Local';

  constructor(
    private formBuilder: FormBuilder,
    private router: Router,
    private authService: AuthService
  ) {
    this.loginForm = this.formBuilder.group({
      email: ['', Validators.required],
      password: ['', Validators.required]
    });

    this.registerForm = this.formBuilder.group({
      username: ['', Validators.required],
      email: ['', Validators.required],
      password: ['', Validators.required]
    });
  }

  toggleMode() {
    this.isLoginMode = !this.isLoginMode;
    this.error = '';
    this.successMessage = '';
  }

  onEmailPhoneInput(event: any) {
    const input = event.target;
    const value = input.value;
    
    // If it looks like a phone number (starts with digit), apply mask
    if (/^\d/.test(value.replace(/\D/g, ''))) {
        let numbers = value.replace(/\D/g, '');
        if (numbers.length > 11) numbers = numbers.substring(0, 11);
        
        let formatted = numbers;
        if (numbers.length > 2) formatted = `(${numbers.substring(0, 2)}) ${numbers.substring(2)}`;
        if (numbers.length > 7) formatted = `(${numbers.substring(0, 2)}) ${numbers.substring(2, 7)}-${numbers.substring(7)}`;
        
        const control = this.isLoginMode ? this.loginForm.get('email') : this.registerForm.get('email');
        if (control && control.value !== formatted) {
            control.setValue(formatted, { emitEvent: false });
        }
    }
  }

  onSubmit() {
    this.error = '';
    this.successMessage = '';
    
    if (this.isLoginMode) {
      if (this.loginForm.invalid) return;
      
      this.authService.login(this.loginForm.value.email, this.loginForm.value.password)
        .subscribe({
          next: (response) => {
            if (response.success) {
                this.router.navigate(['/chat']);
            } else {
                this.error = response.message || 'Login failed. Please check your credentials.';
            }
          },
          error: (error) => {
            this.error = 'Login failed. Please check your credentials.';
          }
        });
    } else {
      if (this.registerForm.invalid) return;

      const emailOrPhone = this.registerForm.value.email;
      const isEmail = emailOrPhone.includes('@');

      this.authService.register(
        this.registerForm.value.username,
        isEmail ? emailOrPhone : null,
        !isEmail ? emailOrPhone.replace(/\D/g, '') : null,
        this.registerForm.value.password
      ).subscribe({
        next: (response) => {
          if (response.success) {
              this.isLoginMode = true;
              this.successMessage = 'Registration successful! Please login.';
          } else {
              this.error = response.message || 'Registration failed. Try again.';
          }
        },
        error: (error) => {
          this.error = 'Registration failed. Try again.';
        }
      });
    }
  }
}
