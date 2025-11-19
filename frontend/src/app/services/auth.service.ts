import { Injectable } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { BehaviorSubject, Observable, tap } from 'rxjs';

@Injectable({
  providedIn: 'root'
})
export class AuthService {
  private apiUrl = 'http://localhost:8000/api/auth';
  private currentUserSubject = new BehaviorSubject<any>(JSON.parse(localStorage.getItem('currentUser') || '{}'));
  public currentUser = this.currentUserSubject.asObservable();

  constructor(private http: HttpClient) { }

  public get currentUserValue() {
    return this.currentUserSubject.value;
  }

  login(emailOrPhone: string, password: string): Observable<any> {
    const isEmail = emailOrPhone.includes('@');
    const payload = {
        email: isEmail ? emailOrPhone : null,
        phone: !isEmail ? emailOrPhone.replace(/\D/g, '') : null,
        password
    };
    return this.http.post<any>(`${this.apiUrl}/login`, payload)
      .pipe(tap(user => {
        if (user && user.token) {
          localStorage.setItem('currentUser', JSON.stringify(user));
          this.currentUserSubject.next(user);
        }
      }));
  }

  register(username: string, email: string | null, phone: string | null, password: string): Observable<any> {
    return this.http.post<any>(`${this.apiUrl}/register`, { username, email, phone, password });
  }

  logout() {
    localStorage.removeItem('currentUser');
    this.currentUserSubject.next(null);
  }
}
